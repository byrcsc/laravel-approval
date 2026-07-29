<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Enums\StepState;
use ByRcsc\LaravelApproval\Exceptions\AlreadyDecidedException;
use ByRcsc\LaravelApproval\Exceptions\CommentRequiredException;
use ByRcsc\LaravelApproval\Exceptions\InvalidDraftException;
use ByRcsc\LaravelApproval\Facades\Approval;

it('returns a request to its submitter without rewriting prior approvals', function (): void {
    [$first, $second, $third] = [user('First'), user('Second'), user('Third')];
    $author = user('Author');
    $order = purchaseOrder();
    $request = $order->submitForApproval(workflow([
        ['approvers' => [$first]],
        ['approvers' => [$second]],
        ['approvers' => [$third]],
    ]), ['amount' => 900], $author);

    Approval::approve($request, $first);
    Approval::returnToSubmitter($request, $second, 'Correct the total.');

    expect($request->refresh()->state)->toBe(RequestState::Returned)
        ->and($request->steps()->pluck('state')->all())->toBe([
            StepState::Approved,
            StepState::Returned,
            StepState::Skipped,
        ])
        ->and($request->actions()->where('action', ActionType::Approved->value)->count())->toBe(1)
        ->and($order->fresh()?->amount)->toBe(500);
});

it('requires a reason and an eligible active approver to return', function (): void {
    $approver = user('Approver');
    $request = post()->submitForApproval(workflow([['approvers' => [$approver]]]), null, user('Author'));

    expect(fn () => Approval::returnToSubmitter($request, $approver, ''))
        ->toThrow(CommentRequiredException::class);
});

it('resumes as a linked request at level one with a fresh snapshot', function (): void {
    $first = user('First');
    $second = user('Second');
    $author = user('Author');
    $workflow = workflow([
        ['approvers' => [$first]],
        ['approvers' => [$second]],
    ]);
    $order = purchaseOrder();
    $returned = $order->submitForApproval($workflow, ['reference' => 'PO-NEW', 'amount' => 900], $author);

    Approval::approve($returned, $first);
    Approval::returnToSubmitter($returned, $second, 'Correct the total.');

    $replacement = Approval::resume($returned, ['amount' => 750]);

    expect($replacement->state)->toBe(RequestState::Pending)
        ->and($replacement->current_level_sequence)->toBe(1)
        ->and(canonical($replacement->new_attributes))->toBe(canonical(['reference' => 'PO-NEW', 'amount' => 750]))
        ->and($replacement->resubmittedFrom?->is($returned))->toBeTrue()
        ->and($returned->refresh()->state)->toBe(RequestState::Returned)
        ->and($replacement->actions()->where('action', ActionType::Resumed->value)->count())->toBe(1);
});

it('only lets resume edit attributes already present in the returned draft', function (): void {
    $approver = user('Approver');
    $request = purchaseOrder()->submitForApproval(
        workflow([['approvers' => [$approver]]]),
        ['amount' => 900],
        user('Author'),
    );
    Approval::returnToSubmitter($request, $approver, 'Correct it.');

    expect(fn () => Approval::resume($request, ['reference' => 'PO-X']))
        ->toThrow(InvalidDraftException::class, 'New attributes: reference');
});

it('does not resume requests in any other state', function (): void {
    $request = post()->submitForApproval(
        workflow([['approvers' => [user('Approver')]]]),
        null,
        user('Author'),
    );

    expect(fn () => Approval::resume($request))
        ->toThrow(AlreadyDecidedException::class, 'Only returned requests');
});
