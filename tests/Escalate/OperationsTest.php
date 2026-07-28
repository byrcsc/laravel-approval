<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Enums\EscalationAction;
use ByRcsc\LaravelApproval\Events\StepOverdue;
use ByRcsc\LaravelApproval\Events\StepStranded;
use ByRcsc\LaravelApproval\Facades\Approval;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;

it('sends overdue reminders at the snapshotted cadence without re-escalating', function (): void {
    Event::fake([StepOverdue::class]);
    Date::setTestNow('2026-07-28 10:00:00');

    $request = post()->submitForApproval(workflow([[
        'sla_minutes' => 60,
        'remind_every_minutes' => 30,
        'escalation_strategy' => EscalationAction::Notify,
        'approvers' => [user('Approver')],
    ]]), null, user('Author'));

    Date::setTestNow('2026-07-28 11:01:00');
    expect(Approval::escalateOverdue()['escalated'])->toHaveCount(1);

    Date::setTestNow('2026-07-28 11:20:00');
    expect(Approval::escalateOverdue()['reminded'])->toBe([]);

    Date::setTestNow('2026-07-28 11:31:00');
    expect(Approval::escalateOverdue()['reminded'])->toHaveCount(1);

    Date::setTestNow('2026-07-28 12:01:00');
    expect(Approval::escalateOverdue()['reminded'])->toHaveCount(1)
        ->and($request->actions()->where('action', ActionType::Escalated->value)->count())->toBe(1)
        ->and($request->actions()->where('action', ActionType::Reminded->value)->count())->toBe(2);

    Event::assertDispatchedTimes(StepOverdue::class, 3);
});

it('flags a deleted sole approver in status, doctor, and the sweep event', function (): void {
    Event::fake([StepStranded::class]);
    $approver = user('Departed approver');
    $request = post()->submitForApproval(
        workflow([['approvers' => [$approver]]]),
        null,
        user('Author'),
    );
    $approver->delete();

    $this->artisan('approval:status')
        ->expectsOutputToContain('Stranded')
        ->expectsOutputToContain('0 living / 1 required')
        ->assertSuccessful();

    $this->artisan('approval:doctor')
        ->expectsOutputToContain('Found 1 stranded approval level')
        ->assertFailed();

    $first = Approval::escalateOverdue();
    $second = Approval::escalateOverdue();

    expect($first['stranded'])->toHaveCount(1)
        ->and($second['stranded'])->toBe([])
        ->and($request->steps()->sole()->stranded_at)->not->toBeNull();

    Event::assertDispatchedTimes(StepStranded::class, 1);
});

it('clears the stranded marker when a dead seat is reassigned', function (): void {
    $departed = user('Departed');
    $replacement = user('Replacement');
    $request = post()->submitForApproval(
        workflow([['approvers' => [$departed]]]),
        null,
        user('Author'),
    );
    $seat = $request->steps()->sole()->approverSlots()->sole();
    $departed->delete();
    Approval::escalateOverdue();

    Approval::reassign($request, $seat, $replacement, user('Administrator'));

    expect($request->steps()->sole()->stranded_at)->toBeNull();
});

it('does not treat the original approver as living when a delegate is deleted', function (): void {
    $original = user('Original');
    $second = user('Second');
    $delegate = user('Delegate');
    $request = post()->submitForApproval(
        workflow([['required_approvals' => 2, 'approvers' => [$original, $second]]]),
        null,
        user('Author'),
    );

    Approval::delegate($request, $original, $delegate);
    $delegate->delete();

    expect(Approval::escalateOverdue()['stranded'])->toHaveCount(1);
});
