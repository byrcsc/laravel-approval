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

it('rewrites who a seat belongs to', function (): void {
    $leaver = user('Leaver');
    $successor = user('Successor');

    $workflow = workflow([['approvers' => [$leaver]]]);
    $request = post()->submitForApproval($workflow, null, user());
    $seat = $request->steps()->sole()->approverSlots()->sole();

    Approval::reassign($request, $seat, $successor, user('Admin'), 'Left in March.');

    expect($seat->refresh()->approver?->getKey())->toBe($successor->getKey())
        ->and($seat->source)->toBe(ApproverSource::Reassigned)
        ->and($seat->delegatedTo)->toBeNull();
});

it('lets the successor carry the level', function (): void {
    $leaver = user('Leaver');
    $successor = user('Successor');

    $workflow = workflow([['approvers' => [$leaver]]]);
    $request = post()->submitForApproval($workflow, null, user());

    Approval::reassign($request, $request->steps()->sole()->approverSlots()->sole(), $successor);
    Approval::approve($request, $successor);

    expect($request->refresh()->state)->toBe(RequestState::Approved);
});

it('takes the seat away from whoever held it', function (): void {
    $leaver = user('Leaver');

    $workflow = workflow([['approvers' => [$leaver]]]);
    $request = post()->submitForApproval($workflow, null, user());

    Approval::reassign($request, $request->steps()->sole()->approverSlots()->sole(), user('Successor'));

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
    Approval::reassign($request, $request->steps()->sole()->approverSlots()->sole(), $successor);

    expect(fn () => Approval::approve($request, $delegate))
        ->toThrow(UnauthorizedApproverException::class);

    Approval::approve($request, $successor);

    expect($request->refresh()->state)->toBe(RequestState::Approved);
});

it('works on a level the request has not reached yet', function (): void {
    $first = user('First');
    $leaver = user('Leaver');
    $successor = user('Successor');

    $workflow = workflow([
        ['approvers' => [$first]],
        ['approvers' => [$leaver]],
    ]);

    $request = post()->submitForApproval($workflow, null, user());
    $queued = $request->steps()->get()[1];

    Approval::reassign($request, $queued->approverSlots()->sole(), $successor);

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

    Approval::reassign($request, $request->steps()->sole()->approverSlots()->sole(), $successor, $admin, 'Left in March.');

    $action = $request->actions()->where('action', 'reassigned')->sole();

    expect($action->actor?->getKey())->toBe($admin->getKey())
        ->and($action->getAttributeValue('comment'))->toBe('Left in March.')
        ->and($action->getAttributeValue('metadata'))->toBe([
            'from' => $leaver->getMorphClass().':'.$leaver->getKey(),
            'to' => $successor->getMorphClass().':'.$successor->getKey(),
        ]);

    Event::assertDispatched(
        ApproverReassigned::class,
        fn (ApproverReassigned $e): bool => $e->from?->is($leaver) === true && $e->to->is($successor),
    );
});

describe('what reassignment refuses', function (): void {
    it('refuses moving a seat that already voted', function (): void {
        $one = user('One');
        $workflow = workflow([['required_approvals' => 2, 'approvers' => [$one, user('Two')]]]);
        $request = post()->submitForApproval($workflow, null, user());

        $seat = $request->steps()->sole()->approverSlots()->orderBy('id')->first();

        Approval::approve($request, $one);

        expect(fn () => Approval::reassign($request, $seat, user('Successor')))
            ->toThrow(AlreadyDecidedException::class, 'behind a decision they never made');
    });

    it('refuses moving an unused seat on a completed level', function (): void {
        $one = user('One');
        $two = user('Two');
        $workflow = workflow([
            ['required_approvals' => 1, 'approvers' => [$one, $two]],
            ['approvers' => [user('Next')]],
        ]);

        $request = post()->submitForApproval($workflow, null, user('Author'));
        $completedStep = $request->steps()->orderBy('sequence')->first();
        $unusedSeat = $completedStep->approverSlots()->where('approver_id', $two->getKey())->sole();

        Approval::approve($request, $one);

        expect(fn () => Approval::reassign($request, $unusedSeat, user('Replacement')))
            ->toThrow(AlreadyDecidedException::class, 'level has already been decided');
    });

    it('refuses moving a seat onto somebody who already has one', function (): void {
        $one = user('One');
        $two = user('Two');

        $workflow = workflow([['required_approvals' => 2, 'approvers' => [$one, $two]]]);
        $request = post()->submitForApproval($workflow, null, user());

        $seat = $request->steps()->sole()->approverSlots()->orderBy('id')->first();

        expect(fn () => Approval::reassign($request, $seat, $two))
            ->toThrow(DelegationException::class, 'already holds a seat');
    });

    it('refuses a seat that belongs to a different request', function (): void {
        $workflow = workflow([['approvers' => [user('A')]]]);
        $mine = post('Mine')->submitForApproval($workflow, null, user());
        $theirs = post('Theirs')->submitForApproval(
            workflow([['approvers' => [user('B')]]]),
            null,
            user(),
        );

        $foreign = $theirs->steps()->sole()->approverSlots()->sole();

        expect(fn () => Approval::reassign($mine, $foreign, user('Successor')))
            ->toThrow(AlreadyDecidedException::class, 'does not belong to approval request');
    });

    it('refuses on a closed request', function (): void {
        $approver = user('Approver');
        $workflow = workflow([['approvers' => [$approver]]]);
        $request = post()->submitForApproval($workflow, null, user());
        $seat = $request->steps()->sole()->approverSlots()->sole();

        Approval::approve($request, $approver);

        expect(fn () => Approval::reassign($request, $seat, user('Successor')))
            ->toThrow(AlreadyDecidedException::class, 'cannot be decided again');
    });
});
