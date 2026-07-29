<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Enums\EscalationAction;
use ByRcsc\LaravelApproval\Exceptions\DelegationException;
use ByRcsc\LaravelApproval\Exceptions\InvalidWorkflowException;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Tests\Stubs\HeadOfDepartmentResolver;
use ByRcsc\LaravelApproval\Tests\Stubs\User;
use Illuminate\Support\Facades\Date;

// The living-quorum invariant, from the other side: it exists to stop a seat
// mutation stranding a level, and it must not stop the mutations that are
// somebody trying to un-strand one.

it('repairs a level stranded by two dead seats, one seat at a time', function (): void {
    $first = user('First');
    $second = user('Second');
    $request = post()->submitForApproval(
        workflow([['required_approvals' => 2, 'approvers' => [$first, $second]]]),
        null,
        user('Author'),
    );

    $seats = $request->steps()->sole()->approverSlots()->orderBy('id')->get();
    $first->delete();
    $second->delete();
    Approval::escalateOverdue();

    Approval::reassign($request, $seats[0], user('Replacement one'), user('Administrator'));
    Approval::reassign($request, $seats[1], user('Replacement two'), user('Administrator'));

    expect($request->steps()->sole()->stranded_at)->toBeNull()
        ->and($request->actions()->where('action', ActionType::Reassigned->value)->count())->toBe(2);
});

it('lets an untouched seat be delegated while the level is stranded', function (): void {
    $living = user('Living');
    $departed = user('Departed');
    $request = post()->submitForApproval(
        workflow([['required_approvals' => 2, 'approvers' => [$living, $departed]]]),
        null,
        user('Author'),
    );

    $departed->delete();
    Approval::escalateOverdue();

    Approval::delegate($request, $living, user('Delegate'));

    expect($request->actions()->where('action', ActionType::Delegated->value)->count())->toBe(1)
        ->and($request->steps()->sole()->stranded_at)->not->toBeNull();
});

it('lets escalation add a seat to a level that is still short afterwards', function (): void {
    Date::setTestNow('2026-07-28 10:00:00');
    $one = user('One');
    $two = user('Two');
    $three = user('Three');
    HeadOfDepartmentResolver::$heads = [user('Escalation target')];

    $request = post()->submitForApproval(workflow([[
        'required_approvals' => 3,
        'sla_minutes' => 60,
        'escalation_strategy' => EscalationAction::Escalate,
        'escalation_config' => ['resolver' => HeadOfDepartmentResolver::class],
        'approvers' => [$one, $two, $three],
    ]]), null, user('Author'));

    $one->delete();
    $two->delete();
    $three->delete();

    Date::setTestNow('2026-07-28 11:01:00');
    $sweep = Approval::escalateOverdue();

    expect($sweep['failed'])->toBe([])
        ->and($sweep['escalated'])->toHaveCount(1)
        ->and($request->steps()->sole()->approverSlots()->count())->toBe(4);
});

it('still refuses a reassignment that hands a seat to somebody already gone', function (): void {
    $approver = user('Approver');
    $ghost = user('Ghost');
    $request = post()->submitForApproval(
        workflow([['approvers' => [$approver]]]),
        null,
        user('Author'),
    );

    $seat = $request->steps()->sole()->approverSlots()->sole();
    $ghost->delete();

    expect(fn () => Approval::reassign($request, $seat, $ghost, user('Administrator')))
        ->toThrow(DelegationException::class, 'no longer exists');

    expect($seat->refresh()->approver_id)->toBe($approver->getKey())
        ->and($request->actions()->where('action', ActionType::Reassigned->value)->count())->toBe(0);
});

it('still refuses a seat handed to a record deleted out from under the caller', function (): void {
    $approver = user('Approver');
    $successor = user('Successor');
    $request = post()->submitForApproval(
        workflow([['approvers' => [$approver]]]),
        null,
        user('Author'),
    );

    $seat = $request->steps()->sole()->approverSlots()->sole();

    // Still `exists` as far as this instance knows, but gone from the table:
    // the invariant, not the existence check, is what has to catch this.
    User::query()->whereKey($successor->getKey())->delete();

    expect(fn () => Approval::reassign($request, $seat, $successor, user('Administrator')))
        ->toThrow(InvalidWorkflowException::class, 'living seat holders');

    expect($seat->refresh()->approver_id)->toBe($approver->getKey());
});

it('still refuses a delegation that hands a seat to somebody already gone', function (): void {
    $approver = user('Approver');
    $ghost = user('Ghost');
    $request = post()->submitForApproval(
        workflow([['approvers' => [$approver]]]),
        null,
        user('Author'),
    );

    $ghost->delete();

    expect(fn () => Approval::delegate($request, $approver, $ghost))
        ->toThrow(DelegationException::class, 'no longer exists');

    expect($request->steps()->sole()->approverSlots()->sole()->delegated_to_id)->toBeNull()
        ->and($request->actions()->where('action', ActionType::Delegated->value)->count())->toBe(0);
});
