<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Enums\EscalationAction;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Exceptions\DelegationException;
use ByRcsc\LaravelApproval\Exceptions\SelfApprovalException;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Tests\Stubs\HeadOfDepartmentResolver;
use Illuminate\Support\Facades\Date;

/**
 * Escalation, delegation, and required approvals are three ways for a stage's assignments to move
 * underneath it. These are the places where two of them meet.
 */
beforeEach(function (): void {
    Date::setTestNow('2026-07-28 09:00:00');
});

afterEach(function (): void {
    Date::setTestNow();
});

it('escalates a stage whose assignment was delegated but never used', function (): void {
    $duty = user('Duty Officer');
    HeadOfDepartmentResolver::$heads = [$duty];

    $request = overdueRequest(
        EscalationAction::Escalate,
        ['resolver' => HeadOfDepartmentResolver::class],
    );

    $original = $request->stages()->sole()->assignments()->sole()->approver;
    Approval::delegate($request, $original, user('Delegate'));

    Date::setTestNow('2026-07-28 10:01:00');
    Approval::escalateOverdue();

    expect($request->stages()->sole()->assignments()->count())->toBe(2);

    Approval::approve($request, $duty);

    expect($request->refresh()->state)->toBe(RequestState::Approved);
});

it('pins an all-assignments required approvals to the assignment count when an assignment was delegated', function (): void {
    $duty = user('Duty Officer');
    HeadOfDepartmentResolver::$heads = [$duty];

    $request = overdueRequest(
        EscalationAction::Escalate,
        ['resolver' => HeadOfDepartmentResolver::class],
        requiredApprovals: null,
    );

    $original = $request->stages()->sole()->assignments()->sole()->approver;
    Approval::delegate($request, $original, user('Delegate'));

    Date::setTestNow('2026-07-28 10:01:00');
    Approval::escalateOverdue();

    $stage = $request->stages()->sole();

    expect($stage->getAttributeValue('required_approvals'))->toBe(1)
        ->and($stage->assignments()->count())->toBe(2);

    Approval::approve($request, $duty);

    expect($request->refresh()->state)->toBe(RequestState::Approved);
});

it('will not escalate somebody who is already holding a delegated assignment', function (): void {
    $delegate = user('Delegate');
    HeadOfDepartmentResolver::$heads = [$delegate];

    $request = overdueRequest(
        EscalationAction::Escalate,
        ['resolver' => HeadOfDepartmentResolver::class],
        requiredApprovals: 2,
        approvers: 2,
    );

    $assignments = $request->stages()->sole()->assignments()->orderBy('id')->get();
    Approval::delegate($request, $assignments[0]->approver, $delegate);

    Date::setTestNow('2026-07-28 10:01:00');
    Approval::escalateOverdue();

    expect($request->stages()->sole()->assignments()->count())->toBe(2);

    Approval::approve($request, $delegate);

    expect($request->refresh()->state)->toBe(RequestState::Pending);
});

it('does not escalate a stage that reached its required approvals in time', function (): void {
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

it('auto-rejects a stage that was one decision short when the clock ran out', function (): void {
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
        // The decision that was cast is still in the trail.
        ->and($request->actions()->where('action', 'approved')->count())->toBe(1);
});

it('lets a delegate carry a stage after it was escalated', function (): void {
    HeadOfDepartmentResolver::$heads = [user('Duty Officer')];

    $request = overdueRequest(
        EscalationAction::Escalate,
        ['resolver' => HeadOfDepartmentResolver::class],
    );

    Date::setTestNow('2026-07-28 10:01:00');
    Approval::escalateOverdue();

    $original = $request->stages()->sole()->assignments()->orderBy('id')->first()->approver;
    $delegate = user('Delegate');

    Approval::delegate($request, $original, $delegate);
    Approval::approve($request, $delegate);

    expect($request->refresh()->state)->toBe(RequestState::Approved);
});

it('still refuses a delegation that would strand the escalated stage', function (): void {
    $duty = user('Duty Officer');
    HeadOfDepartmentResolver::$heads = [$duty];

    $request = overdueRequest(
        EscalationAction::Escalate,
        ['resolver' => HeadOfDepartmentResolver::class],
    );

    Date::setTestNow('2026-07-28 10:01:00');
    Approval::escalateOverdue();

    $original = $request->stages()->sole()->assignments()->orderBy('id')->first()->approver;

    // Handing the assignment to the person escalation just seated would leave one
    // holder on two assignments.
    expect(fn () => Approval::delegate($request, $original, $duty))
        ->toThrow(DelegationException::class, 'already has an assignment');
});

it('lets an escalated assignment be delegated onward like any other', function (): void {
    $duty = user('Duty Officer');
    HeadOfDepartmentResolver::$heads = [$duty];

    $request = overdueRequest(
        EscalationAction::Escalate,
        ['resolver' => HeadOfDepartmentResolver::class],
    );

    Date::setTestNow('2026-07-28 10:01:00');
    Approval::escalateOverdue();

    // The duty officer escalation brought in is away too. Their assignment obeys
    // the same rules as one the workflow assigned.
    $stand = user('Stand-in');
    Approval::delegate($request, $duty, $stand);

    Approval::approve($request, $stand);

    expect($request->refresh()->state)->toBe(RequestState::Approved);
});

it('assignments the submitter when escalation names them, but the decision rule still holds', function (): void {
    $request = overdueRequest(
        EscalationAction::Escalate,
        ['resolver' => HeadOfDepartmentResolver::class],
    );

    $submitter = $request->submitter;
    assert($submitter !== null);

    // The org chart says escalations go to the author of this very request.
    HeadOfDepartmentResolver::$heads = [$submitter];

    Date::setTestNow('2026-07-28 10:01:00');
    Approval::escalateOverdue();

    expect($request->stages()->sole()->assignments()->count())->toBe(2);

    // The assignment exists; the self-approval rule bites at the decision, as always.
    expect(fn () => Approval::approve($request, $submitter))
        ->toThrow(SelfApprovalException::class);

    $original = $request->stages()->sole()->assignments()->orderBy('id')->first()->approver;
    Approval::approve($request, $original);

    expect($request->refresh()->state)->toBe(RequestState::Approved);
});

it('counts a decision cast before the escalation toward the pinned required approvals', function (): void {
    $duty = user('Duty Officer');
    HeadOfDepartmentResolver::$heads = [$duty];

    // All-of-them across two assignments; one decision lands in time, one does not.
    $request = overdueRequest(
        EscalationAction::Escalate,
        ['resolver' => HeadOfDepartmentResolver::class],
        requiredApprovals: null,
        approvers: 2,
    );

    $assignments = $request->stages()->sole()->assignments()->orderBy('id')->get();
    Approval::approve($request, $assignments[0]->approver);

    Date::setTestNow('2026-07-28 10:01:00');
    Approval::escalateOverdue();

    $stage = $request->stages()->sole();

    expect($stage->getAttributeValue('required_approvals'))->toBe(2)
        ->and($stage->assignments()->count())->toBe(3);

    // The slow original comes through after all: their decision joins the early
    // one, and the duty officer never has to act.
    Approval::approve($request, $assignments[1]->approver);

    expect($request->refresh()->state)->toBe(RequestState::Approved);
});

it('escalates a resynced stage on the deadline it already had', function (): void {
    $head = user('Head');
    HeadOfDepartmentResolver::$heads = [$head];

    $workflow = workflow([[
        'sla_minutes' => 60,
        'required_approvals' => 1,
        'escalation_strategy' => EscalationAction::RecordOnly,
        'approvers' => [['resolver' => HeadOfDepartmentResolver::class]],
    ]]);

    $request = post()->submitForApproval($workflow, null, user('Author'));

    Date::setTestNow('2026-07-28 09:30:00');
    HeadOfDepartmentResolver::$heads = [$head, user('Deputy')];
    Approval::resync($request, user('Operator'));

    // Resyncing assignments does not restart the clock.
    expect($request->stages()->sole()->deadline_at?->toDateTimeString())->toBe('2026-07-28 10:00:00');

    Date::setTestNow('2026-07-28 10:01:00');

    expect(Approval::escalateOverdue()['escalated'])->toHaveCount(1);
});
