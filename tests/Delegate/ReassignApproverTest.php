<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Enums\ApproverSource;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Events\ApproverReassigned;
use ByRcsc\LaravelApproval\Exceptions\AlreadyDecidedException;
use ByRcsc\LaravelApproval\Exceptions\DelegationException;
use ByRcsc\LaravelApproval\Exceptions\UnauthorizedApproverException;
use ByRcsc\LaravelApproval\Facades\Approval;
use Illuminate\Support\Facades\Event;

it('rewrites who an assignment belongs to', function (): void {
    $leaver = user('Leaver');
    $successor = user('Successor');

    $workflow = workflow([['approvers' => [$leaver]]]);
    $request = post()->submitForApproval($workflow, null, user());
    $assignment = $request->stages()->sole()->assignments()->sole();

    Approval::reassign($request, $assignment, $successor, user('Admin'), 'Left in March.');

    expect($assignment->refresh()->approver?->getKey())->toBe($successor->getKey())
        ->and($assignment->source)->toBe(ApproverSource::Reassigned)
        ->and($assignment->delegatedTo)->toBeNull();
});

it('lets the successor carry the stage', function (): void {
    $leaver = user('Leaver');
    $successor = user('Successor');

    $workflow = workflow([['approvers' => [$leaver]]]);
    $request = post()->submitForApproval($workflow, null, user());

    Approval::reassign($request, $request->stages()->sole()->assignments()->sole(), $successor, user('Admin'));
    Approval::approve($request, $successor);

    expect($request->refresh()->state)->toBe(RequestState::Approved);
});

it('takes the assignment away from whoever held it', function (): void {
    $leaver = user('Leaver');

    $workflow = workflow([['approvers' => [$leaver]]]);
    $request = post()->submitForApproval($workflow, null, user());

    Approval::reassign($request, $request->stages()->sole()->assignments()->sole(), user('Successor'), user('Admin'));

    expect(fn () => Approval::approve($request, $leaver))
        ->toThrow(UnauthorizedApproverException::class);
});

it('clears a delegation the departed approver had made', function (): void {
    $leaver = user('Leaver');
    $delegate = user('Delegate');
    $successor = user('Successor');

    $workflow = workflow([['approvers' => [$leaver]]]);
    $request = post()->submitForApproval($workflow, null, user());

    Approval::delegate($request, $leaver, $delegate);
    Approval::reassign($request, $request->stages()->sole()->assignments()->sole(), $successor, user('Admin'));

    expect(fn () => Approval::approve($request, $delegate))
        ->toThrow(UnauthorizedApproverException::class);

    Approval::approve($request, $successor);

    expect($request->refresh()->state)->toBe(RequestState::Approved);
});

it('works on a stage the request has not reached yet', function (): void {
    $first = user('First');
    $leaver = user('Leaver');
    $successor = user('Successor');

    $workflow = workflow([
        ['approvers' => [$first]],
        ['approvers' => [$leaver]],
    ]);

    $request = post()->submitForApproval($workflow, null, user());
    $queued = $request->stages()->get()[1];

    Approval::reassign($request, $queued->assignments()->sole(), $successor, user('Admin'));

    Approval::approve($request, $first);
    Approval::approve($request, $successor);

    expect($request->refresh()->state)->toBe(RequestState::Approved);
});

it('records what it moved and announces it', function (): void {
    Event::fake([ApproverReassigned::class]);

    $leaver = user('Leaver');
    $successor = user('Successor');
    $admin = user('Admin');

    $workflow = workflow([['approvers' => [$leaver]]]);
    $request = post()->submitForApproval($workflow, null, user());

    Approval::reassign($request, $request->stages()->sole()->assignments()->sole(), $successor, $admin, 'Left in March.');

    $action = $request->actions()->where('action', 'reassigned')->sole();

    expect($action->actor?->getKey())->toBe($admin->getKey())
        ->and($action->getAttributeValue('comment'))->toBe('Left in March.')
        ->and(canonical($action->getAttributeValue('metadata')))->toBe(canonical([
            'from' => $leaver->getMorphClass().':'.$leaver->getKey(),
            'to' => $successor->getMorphClass().':'.$successor->getKey(),
        ]));

    Event::assertDispatched(
        ApproverReassigned::class,
        fn (ApproverReassigned $e): bool => $e->from?->is($leaver) === true && $e->to->is($successor),
    );
});

describe('what reassignment refuses', function (): void {
    it('refuses moving an assignment that already decided', function (): void {
        $one = user('One');
        $workflow = workflow([['required_approvals' => 2, 'approvers' => [$one, user('Two')]]]);
        $request = post()->submitForApproval($workflow, null, user());

        $assignment = $request->stages()->sole()->assignments()->orderBy('id')->first();

        Approval::approve($request, $one);

        expect(fn () => Approval::reassign($request, $assignment, user('Successor'), user('Admin')))
            ->toThrow(AlreadyDecidedException::class, 'already has a decision and cannot be moved');
    });

    it('refuses moving an unused assignment on a completed stage', function (): void {
        $one = user('One');
        $two = user('Two');
        $workflow = workflow([
            ['required_approvals' => 1, 'approvers' => [$one, $two]],
            ['approvers' => [user('Next')]],
        ]);

        $request = post()->submitForApproval($workflow, null, user('Author'));
        $completedStep = $request->stages()->orderBy('sequence')->first();
        $unusedSeat = $completedStep->assignments()->where('approver_id', $two->getKey())->sole();

        Approval::approve($request, $one);

        expect(fn () => Approval::reassign($request, $unusedSeat, user('Replacement'), user('Admin')))
            ->toThrow(AlreadyDecidedException::class, 'stage is complete');
    });

    it('refuses moving an assignment onto somebody who already has one', function (): void {
        $one = user('One');
        $two = user('Two');

        $workflow = workflow([['required_approvals' => 2, 'approvers' => [$one, $two]]]);
        $request = post()->submitForApproval($workflow, null, user());

        $assignment = $request->stages()->sole()->assignments()->orderBy('id')->first();

        expect(fn () => Approval::reassign($request, $assignment, $two, user('Admin')))
            ->toThrow(DelegationException::class, 'already has an assignment');
    });

    it('refuses an assignment that belongs to a different request', function (): void {
        $workflow = workflow([['approvers' => [user('A')]]]);
        $mine = post('Mine')->submitForApproval($workflow, null, user());
        $theirs = post('Theirs')->submitForApproval(
            workflow([['approvers' => [user('B')]]]),
            null,
            user(),
        );

        $foreign = $theirs->stages()->sole()->assignments()->sole();

        expect(fn () => Approval::reassign($mine, $foreign, user('Successor'), user('Admin')))
            ->toThrow(AlreadyDecidedException::class, 'does not belong to approval request');
    });

    it('refuses on a closed request', function (): void {
        $approver = user('Approver');
        $workflow = workflow([['approvers' => [$approver]]]);
        $request = post()->submitForApproval($workflow, null, user());
        $assignment = $request->stages()->sole()->assignments()->sole();

        Approval::approve($request, $approver);

        expect(fn () => Approval::reassign($request, $assignment, user('Successor'), user('Admin')))
            ->toThrow(AlreadyDecidedException::class, 'cannot be decided again');
    });
});
