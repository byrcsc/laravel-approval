<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Enums\EscalationAction;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Events\StageOverdue;
use ByRcsc\LaravelApproval\Events\StageOverdueReminder;
use ByRcsc\LaravelApproval\Events\StageStranded;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Tests\Stubs\HeadOfDepartmentResolver;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;

it('sends overdue reminders at the snapshotted cadence without re-escalating', function (): void {
    Event::fake([StageOverdue::class, StageOverdueReminder::class]);
    Date::setTestNow('2026-07-28 10:00:00');

    $request = post()->submitForApproval(workflow([[
        'sla_minutes' => 60,
        'remind_every_minutes' => 30,
        'escalation_strategy' => EscalationAction::RecordOnly,
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

    Event::assertDispatchedTimes(StageOverdue::class, 1);
    Event::assertDispatchedTimes(StageOverdueReminder::class, 2);
});

it('flags a deleted sole approver in status, doctor, and the sweep event', function (): void {
    Event::fake([StageStranded::class]);
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
        ->expectsOutputToContain('Found 1 stranded approval stage')
        ->assertFailed();

    $first = Approval::escalateOverdue();
    $second = Approval::escalateOverdue();

    expect($first['stranded'])->toHaveCount(1)
        ->and($second['stranded'])->toBe([])
        ->and($request->stages()->sole()->stranded_at)->not->toBeNull();

    Event::assertDispatchedTimes(StageStranded::class, 1);
});

it('clears the stranded marker when a dead assignment is reassigned', function (): void {
    $departed = user('Departed');
    $replacement = user('Replacement');
    $request = post()->submitForApproval(
        workflow([['approvers' => [$departed]]]),
        null,
        user('Author'),
    );
    $assignment = $request->stages()->sole()->assignments()->sole();
    $departed->delete();
    Approval::escalateOverdue();

    Approval::reassign($request, $assignment, $replacement, user('Administrator'));

    expect($request->stages()->sole()->stranded_at)->toBeNull();
});

it('keeps the cadence the request was submitted under', function (): void {
    Date::setTestNow('2026-07-28 10:00:00');
    $workflow = workflow([[
        'sla_minutes' => 60,
        'remind_every_minutes' => 30,
        'approvers' => [user('Approver')],
    ]]);

    post()->submitForApproval($workflow, null, user('Author'));

    // The workflow is edited while the request is in flight. Per the snapshot
    // rule, the request keeps the cadence it was submitted under.
    $workflow->stages()->sole()->update(['remind_every_minutes' => 5]);

    Date::setTestNow('2026-07-28 11:01:00');
    Approval::escalateOverdue();

    Date::setTestNow('2026-07-28 11:10:00');
    expect(Approval::escalateOverdue()['reminded'])->toBe([]);

    Date::setTestNow('2026-07-28 11:31:00');
    expect(Approval::escalateOverdue()['reminded'])->toHaveCount(1);
});

it('stops reminding once the strategy has decided the stage', function (): void {
    Date::setTestNow('2026-07-28 10:00:00');
    $request = post()->submitForApproval(workflow([[
        'sla_minutes' => 60,
        'remind_every_minutes' => 30,
        'escalation_strategy' => EscalationAction::AutoApprove,
        'approvers' => [user('Approver')],
    ]]), null, user('Author'));

    Date::setTestNow('2026-07-28 11:01:00');
    Approval::escalateOverdue();

    Date::setTestNow('2026-07-28 13:00:00');

    expect(Approval::escalateOverdue()['reminded'])->toBe([])
        ->and($request->refresh()->state)->toBe(RequestState::Approved)
        ->and($request->actions()->where('action', ActionType::Reminded->value)->count())->toBe(0);
});

it('never escalates a second time, however many reminders it sends', function (): void {
    Date::setTestNow('2026-07-28 10:00:00');
    HeadOfDepartmentResolver::$heads = [user('Duty officer')];

    $request = post()->submitForApproval(workflow([[
        'sla_minutes' => 60,
        'remind_every_minutes' => 30,
        'escalation_strategy' => EscalationAction::Escalate,
        'escalation_config' => ['resolver' => HeadOfDepartmentResolver::class],
        'approvers' => [user('Approver')],
    ]]), null, user('Author'));

    Date::setTestNow('2026-07-28 11:01:00');
    Approval::escalateOverdue();

    HeadOfDepartmentResolver::$heads = [user('Somebody else entirely')];

    Date::setTestNow('2026-07-28 11:31:00');
    Approval::escalateOverdue();

    Date::setTestNow('2026-07-28 12:01:00');
    Approval::escalateOverdue();

    expect($request->stages()->sole()->assignments()->count())->toBe(2)
        ->and($request->actions()->where('action', ActionType::Reminded->value)->count())->toBe(2);
});

it('strands an all-of-them stage the moment one holder goes', function (): void {
    $first = user('First');
    $second = user('Second');
    $request = post()->submitForApproval(
        workflow([['required_approvals' => null, 'approvers' => [$first, $second]]]),
        null,
        user('Author'),
    );

    expect(Approval::escalateOverdue()['stranded'])->toBe([]);

    $second->delete();

    expect(Approval::escalateOverdue()['stranded'])->toHaveCount(1)
        ->and($request->stages()->sole()->stranded_at)->not->toBeNull();
});

it('caps what the doctor lists without hiding that it found more', function (): void {
    $this->artisan('approval:doctor')->assertSuccessful();

    foreach (range(1, 3) as $index) {
        $approver = user('Departed '.$index);
        post()->submitForApproval(workflow([['approvers' => [$approver]]]), null, user('Author'));
        $approver->delete();
    }

    $this->artisan('approval:doctor --limit=2')
        ->expectsOutputToContain('Found 2 stranded approval stages')
        ->assertFailed();
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
