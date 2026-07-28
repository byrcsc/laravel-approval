<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Enums\EscalationAction;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Exceptions\DelegationException;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Tests\Stubs\HeadOfDepartmentResolver;
use Illuminate\Support\Facades\Date;

/**
 * Escalation, delegation, and quorum are three ways for a level's seats to move
 * underneath it. These are the places where two of them meet.
 */
beforeEach(function (): void {
    Date::setTestNow('2026-07-28 09:00:00');
});

afterEach(function (): void {
    Date::setTestNow();
});

it('escalates a level whose seat was delegated but never used', function (): void {
    $duty = user('Duty Officer');
    HeadOfDepartmentResolver::$heads = [$duty];

    $request = overdueRequest(
        EscalationAction::Escalate,
        ['resolver' => HeadOfDepartmentResolver::class],
    );

    $original = $request->steps()->sole()->approverSlots()->sole()->approver;
    Approval::delegate($request, $original, user('Delegate'));

    Date::setTestNow('2026-07-28 10:01:00');
    Approval::escalateOverdue();

    expect($request->steps()->sole()->approverSlots()->count())->toBe(2);

    Approval::approve($request, $duty);

    expect($request->refresh()->state)->toBe(RequestState::Approved);
});

it('pins an all-seats quorum to the seat count when a seat was delegated', function (): void {
    $duty = user('Duty Officer');
    HeadOfDepartmentResolver::$heads = [$duty];

    $request = overdueRequest(
        EscalationAction::Escalate,
        ['resolver' => HeadOfDepartmentResolver::class],
        requiredApprovals: null,
    );

    $original = $request->steps()->sole()->approverSlots()->sole()->approver;
    Approval::delegate($request, $original, user('Delegate'));

    Date::setTestNow('2026-07-28 10:01:00');
    Approval::escalateOverdue();

    $step = $request->steps()->sole();

    expect($step->getAttributeValue('required_approvals'))->toBe(1)
        ->and($step->approverSlots()->count())->toBe(2);

    Approval::approve($request, $duty);

    expect($request->refresh()->state)->toBe(RequestState::Approved);
});

it('will not escalate somebody who is already holding a delegated seat', function (): void {
    $delegate = user('Delegate');
    HeadOfDepartmentResolver::$heads = [$delegate];

    $request = overdueRequest(
        EscalationAction::Escalate,
        ['resolver' => HeadOfDepartmentResolver::class],
        requiredApprovals: 2,
        approvers: 2,
    );

    $seats = $request->steps()->sole()->approverSlots()->orderBy('id')->get();
    Approval::delegate($request, $seats[0]->approver, $delegate);

    Date::setTestNow('2026-07-28 10:01:00');
    Approval::escalateOverdue();

    expect($request->steps()->sole()->approverSlots()->count())->toBe(2);

    Approval::approve($request, $delegate);

    expect($request->refresh()->state)->toBe(RequestState::Pending);
});

it('does not escalate a level that reached its quorum in time', function (): void {
    $one = user('One');
    $two = user('Two');

    $workflow = workflow([[
        'sla_minutes' => 60,
        'required_approvals' => 2,
        'escalation_strategy' => EscalationAction::AutoReject,
        'approvers' => [$one, $two],
    ]]);

    $request = post()->submitForApproval($workflow, null, user('Author'));

    Date::setTestNow('2026-07-28 09:30:00');
    Approval::approve($request, $one);
    Approval::approve($request, $two);

    Date::setTestNow('2026-07-28 10:01:00');

    expect(Approval::escalateOverdue()['escalated'])->toBe([])
        ->and($request->refresh()->state)->toBe(RequestState::Approved);
});

it('auto-rejects a level that was one vote short when the clock ran out', function (): void {
    $one = user('One');

    $workflow = workflow([[
        'sla_minutes' => 60,
        'required_approvals' => 2,
        'escalation_strategy' => EscalationAction::AutoReject,
        'approvers' => [$one, user('Two')],
    ]]);

    $request = post()->submitForApproval($workflow, null, user('Author'));

    Date::setTestNow('2026-07-28 09:30:00');
    Approval::approve($request, $one);

    Date::setTestNow('2026-07-28 10:01:00');
    Approval::escalateOverdue();

    expect($request->refresh()->state)->toBe(RequestState::Rejected)
        // The vote that was cast is still in the trail.
        ->and($request->actions()->where('action', 'approved')->count())->toBe(1);
});

it('lets a delegate carry a level after it was escalated', function (): void {
    HeadOfDepartmentResolver::$heads = [user('Duty Officer')];

    $request = overdueRequest(
        EscalationAction::Escalate,
        ['resolver' => HeadOfDepartmentResolver::class],
    );

    Date::setTestNow('2026-07-28 10:01:00');
    Approval::escalateOverdue();

    $original = $request->steps()->sole()->approverSlots()->orderBy('id')->first()->approver;
    $delegate = user('Delegate');

    Approval::delegate($request, $original, $delegate);
    Approval::approve($request, $delegate);

    expect($request->refresh()->state)->toBe(RequestState::Approved);
});

it('still refuses a delegation that would strand the escalated level', function (): void {
    $duty = user('Duty Officer');
    HeadOfDepartmentResolver::$heads = [$duty];

    $request = overdueRequest(
        EscalationAction::Escalate,
        ['resolver' => HeadOfDepartmentResolver::class],
    );

    Date::setTestNow('2026-07-28 10:01:00');
    Approval::escalateOverdue();

    $original = $request->steps()->sole()->approverSlots()->orderBy('id')->first()->approver;

    // Handing the seat to the person escalation just seated would leave one
    // holder on two seats.
    expect(fn () => Approval::delegate($request, $original, $duty))
        ->toThrow(DelegationException::class, 'already holds a seat');
});

it('escalates a resynced level on the deadline it already had', function (): void {
    $head = user('Head');
    HeadOfDepartmentResolver::$heads = [$head];

    $workflow = workflow([[
        'sla_minutes' => 60,
        'required_approvals' => 1,
        'escalation_strategy' => EscalationAction::Notify,
        'approvers' => [['resolver' => HeadOfDepartmentResolver::class]],
    ]]);

    $request = post()->submitForApproval($workflow, null, user('Author'));

    Date::setTestNow('2026-07-28 09:30:00');
    HeadOfDepartmentResolver::$heads = [$head, user('Deputy')];
    Approval::resync($request);

    // Resyncing seats does not restart the clock.
    expect($request->steps()->sole()->deadline_at?->toDateTimeString())->toBe('2026-07-28 10:00:00');

    Date::setTestNow('2026-07-28 10:01:00');

    expect(Approval::escalateOverdue()['escalated'])->toHaveCount(1);
});
