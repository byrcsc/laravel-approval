<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Definitions\WorkflowBuilder;
use ByRcsc\LaravelApproval\Enums\RejectionPolicy;
use ByRcsc\LaravelApproval\Exceptions\InvalidWorkflowException;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Tests\Stubs\AmountExceedsCondition;

it('persists and snapshots the same unanimous default for fluent and array definitions', function (): void {
    $first = user('First');
    $second = user('Second');

    $fluent = WorkflowBuilder::make('fluent-default', 'Fluent default')
        ->stage('Review', fn ($stage) => $stage->approver($first)->approver($second));

    Approval::syncDefinitions([
        $fluent,
        [
            'slug' => 'array-default',
            'name' => 'Array default',
            'stages' => [[
                'name' => 'Review',
                'approvers' => [$first, $second],
            ]],
        ],
    ]);

    $fluentStage = Approval::workflow('fluent-default')->stages()->sole();
    $arrayStage = Approval::workflow('array-default')->stages()->sole();

    expect($fluentStage->getAttributeValue('required_approvals'))->toBeNull()
        ->and($arrayStage->getAttributeValue('required_approvals'))->toBeNull()
        ->and($fluentStage->rejection_policy)->toBe(RejectionPolicy::ApprovalImpossible)
        ->and($arrayStage->rejection_policy)->toBe(RejectionPolicy::ApprovalImpossible);

    foreach ([$fluentStage->workflow, $arrayStage->workflow] as $workflow) {
        $request = post()->submitForApproval($workflow, null, user('Requester'));

        Approval::approve($request, $first);
        expect($request->refresh()->state->value)->toBe('pending');

        Approval::approve($request, $second);
        expect($request->refresh()->state->value)->toBe('approved')
            ->and($request->stages()->sole()->getAttributeValue('required_approvals'))->toBeNull()
            ->and($request->stages()->sole()->rejection_policy)->toBe(RejectionPolicy::ApprovalImpossible);
    }
});

it('interprets omitted rules identically on directly persisted stages and snapshots', function (): void {
    $first = user('First');
    $second = user('Second');
    $workflow = workflow([[
        'required_approvals' => null,
        'approvers' => [$first, $second],
    ]]);

    $stage = $workflow->stages()->sole();

    $request = post()->submitForApproval($workflow, null, user('Requester'));
    $snapshot = $request->stages()->sole();

    expect($snapshot->getAttributeValue('required_approvals'))->toBeNull()
        ->and($snapshot->getAttributeValue('rejection_policy'))->toBe(RejectionPolicy::ApprovalImpossible);

    Approval::approve($request, $first);
    expect($request->refresh()->state->value)->toBe('pending');

    Approval::approve($request, $second);
    expect($request->refresh()->state->value)->toBe('approved');
});

it('retains explicit one of many numeric and unanimous approval rules', function (): void {
    $first = user('First');
    $second = user('Second');
    $third = user('Third');

    foreach ([1, 2, null] as $required) {
        $request = post()->submitForApproval(
            workflow([['required_approvals' => $required, 'approvers' => [$first, $second, $third]]]),
            null,
            user('Requester'),
        );

        $needed = $required ?? 3;

        foreach ([$first, $second, $third] as $index => $approver) {
            Approval::approve($request, $approver);

            expect($request->refresh()->state->value)->toBe($index + 1 >= $needed ? 'approved' : 'pending');

            if ($request->state->value === 'approved') {
                break;
            }
        }
    }
});

it('persists equivalent explicit approval and rejection rules from fluent and array definitions', function (): void {
    $first = user('First');
    $second = user('Second');
    $third = user('Third');

    Approval::syncDefinitions([
        WorkflowBuilder::make('fluent-explicit', 'Fluent explicit')
            ->stage('Review', fn ($stage) => $stage
                ->minimumApprovals(2)
                ->rejectionPolicy(RejectionPolicy::AnyRejectionVetoes)
                ->approver($first)
                ->approver($second)
                ->approver($third)),
        [
            'slug' => 'array-explicit',
            'name' => 'Array explicit',
            'stages' => [[
                'name' => 'Review',
                'required_approvals' => 2,
                'rejection_policy' => RejectionPolicy::AnyRejectionVetoes,
                'approvers' => [$first, $second, $third],
            ]],
        ],
    ]);

    foreach (['fluent-explicit', 'array-explicit'] as $slug) {
        $stage = Approval::workflow($slug)->stages()->sole();
        $snapshot = post()->submitForApproval($stage->workflow, null, user('Requester'))->stages()->sole();

        expect($stage->required_approvals)->toBe(2)
            ->and($stage->rejection_policy)->toBe(RejectionPolicy::AnyRejectionVetoes)
            ->and($snapshot->required_approvals)->toBe(2)
            ->and($snapshot->rejection_policy)->toBe(RejectionPolicy::AnyRejectionVetoes);
    }
});

it('rejects an invalid approval rule before writing a request', function (): void {
    $workflow = workflow([[
        'required_approvals' => 3,
        'approvers' => [user('First'), user('Second')],
    ]], ['slug' => 'invalid-approval-rule']);

    expect(fn () => post()->submitForApproval($workflow, null, user('Requester')))
        ->toThrow(InvalidWorkflowException::class, 'approval rule requires 3 approvals but only 2 assignments were resolved');

    expect($workflow->requests()->count())->toBe(0);
});

it('rejects an invalid approval rule even when its conditional stage is skipped', function (): void {
    $workflow = workflow([[
        'required_approvals' => 3,
        'condition' => AmountExceedsCondition::class,
        'condition_config' => ['amount' => 500_000],
        'approvers' => [user('First'), user('Second')],
    ]], ['slug' => 'invalid-skipped-approval-rule']);

    expect(fn () => purchaseOrder(amount: 100_000)->submitForApproval(
        $workflow,
        ['amount' => 110_000],
        user('Requester'),
    ))->toThrow(
        InvalidWorkflowException::class,
        'approval rule requires 3 approvals but only 2 assignments were resolved',
    );

    expect($workflow->requests()->count())->toBe(0);
});

it('rejects an invalid persisted rejection policy before writing a request', function (): void {
    $workflow = workflow([['approvers' => [user('Approver')]]], ['slug' => 'invalid-rejection-policy']);
    $stage = $workflow->stages()->sole();
    $stage->newQuery()->whereKey($stage->getKey())->update(['rejection_policy' => 'sometimes']);

    expect(fn () => post()->submitForApproval($workflow, null, user('Requester')))
        ->toThrow(InvalidWorkflowException::class, 'invalid rejection policy [sometimes]');

    expect($workflow->requests()->count())->toBe(0);
});
