<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Enums\EscalationAction;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Enums\StepState;
use ByRcsc\LaravelApproval\Events\StepOverdue;
use ByRcsc\LaravelApproval\Facades\Approval;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    Date::setTestNow('2026-07-28 09:00:00');
});

afterEach(function (): void {
    Date::setTestNow();
});

it('leaves a level alone before its deadline', function (): void {
    $request = overdueRequest();

    Date::setTestNow('2026-07-28 09:59:00');

    expect(Approval::escalateOverdue()['escalated'])->toBe([])
        ->and($request->steps()->sole()->escalated_at)->toBeNull();
});

it('escalates a level once its deadline has passed', function (): void {
    Event::fake([StepOverdue::class]);

    $request = overdueRequest();

    Date::setTestNow('2026-07-28 10:01:00');

    expect(Approval::escalateOverdue()['escalated'])->toHaveCount(1)
        ->and($request->steps()->sole()->escalated_at?->toDateTimeString())->toBe('2026-07-28 10:01:00');

    Event::assertDispatched(
        StepOverdue::class,
        fn (StepOverdue $e): bool => $e->action === EscalationAction::Notify,
    );
});

it('escalates each level exactly once however often it runs', function (): void {
    $request = overdueRequest();

    Date::setTestNow('2026-07-28 10:01:00');
    Approval::escalateOverdue();

    Date::setTestNow('2026-07-28 11:00:00');
    $second = Approval::escalateOverdue();

    Date::setTestNow('2026-07-28 18:00:00');
    $third = Approval::escalateOverdue();

    expect($second['escalated'])->toBe([])
        ->and($third['escalated'])->toBe([])
        ->and($request->actions()->where('action', 'escalated')->count())->toBe(1);
});

it('records the deadline it missed', function (): void {
    $request = overdueRequest();

    Date::setTestNow('2026-07-28 10:01:00');
    Approval::escalateOverdue();

    $metadata = $request->actions()->where('action', 'escalated')->sole()->getAttributeValue('metadata');

    expect($metadata['strategy'])->toBe('notify')
        ->and($metadata['deadline_at'])->toStartWith('2026-07-28T10:00:00');
});

it('ignores a level nobody is waiting on', function (): void {
    $first = user('First');

    $workflow = workflow([
        ['approvers' => [$first]],
        ['sla_minutes' => 60, 'approvers' => [user('Second')]],
    ]);

    $request = post()->submitForApproval($workflow, null, user());

    // The second level is queued; its clock has not started.
    Date::setTestNow('2026-07-28 23:00:00');

    expect(Approval::escalateOverdue()['escalated'])->toBe([]);

    // It only starts once the level becomes somebody's turn.
    Approval::approve($request, $first);

    Date::setTestNow('2026-07-29 00:01:00');

    expect(Approval::escalateOverdue()['escalated'])->toHaveCount(1);
});

it('ignores a request that closed before the sweep ran', function (): void {
    $request = overdueRequest();
    $approver = $request->steps()->sole()->approverSlots()->sole()->approver;

    Date::setTestNow('2026-07-28 10:01:00');
    Approval::approve($request, $approver);

    expect(Approval::escalateOverdue()['escalated'])->toBe([]);
});

it('ignores a level with no SLA at all', function (): void {
    $workflow = workflow([['approvers' => [user()]]]);
    post()->submitForApproval($workflow, null, user());

    Date::setTestNow('2027-01-01 00:00:00');

    expect(Approval::escalateOverdue()['escalated'])->toBe([]);
});

it('lists overdue requests without escalating them', function (): void {
    $request = overdueRequest();

    expect(Approval::overdue()->count())->toBe(0);

    Date::setTestNow('2026-07-28 10:01:00');

    expect(Approval::overdue()->pluck('id')->all())->toBe([$request->getKey()])
        ->and($request->steps()->sole()->escalated_at)->toBeNull();
});

it('keeps a request on the overdue list after escalating it', function (): void {
    overdueRequest();

    Date::setTestNow('2026-07-28 10:01:00');
    Approval::escalateOverdue();

    // Being escalated is not the same as being dealt with.
    expect(Approval::overdue()->count())->toBe(1);
});

describe('the notify policy', function (): void {
    it('changes nothing about the request', function (): void {
        $request = overdueRequest();

        Date::setTestNow('2026-07-28 10:01:00');
        Approval::escalateOverdue();

        expect($request->refresh()->state)->toBe(RequestState::Pending)
            ->and($request->steps()->sole()->state)->toBe(StepState::Active)
            ->and($request->steps()->sole()->approverSlots()->count())->toBe(1);
    });

    it('still lets the original approver decide afterwards', function (): void {
        $request = overdueRequest();
        $approver = $request->steps()->sole()->approverSlots()->sole()->approver;

        Date::setTestNow('2026-07-28 10:01:00');
        Approval::escalateOverdue();
        Approval::approve($request, $approver);

        expect($request->refresh()->state)->toBe(RequestState::Approved);
    });
});

describe('the artisan command', function (): void {
    it('reports when nothing is overdue', function (): void {
        overdueRequest();

        $this->artisan('approval:escalate')->assertSuccessful();
    });

    it('escalates and succeeds', function (): void {
        $request = overdueRequest();

        Date::setTestNow('2026-07-28 10:01:00');

        $this->artisan('approval:escalate')->assertSuccessful();

        expect($request->steps()->sole()->escalated_at)->not->toBeNull();
    });

    it('fails when a level cannot be escalated', function (): void {
        // `escalate` with no resolver named is a misconfigured level.
        $request = overdueRequest(EscalationAction::Escalate);

        Date::setTestNow('2026-07-28 10:01:00');

        $this->artisan('approval:escalate')->assertFailed();

        // The failure rolled the step back, so the next run tries again.
        expect($request->steps()->sole()->escalated_at)->toBeNull();
    });

    it('escalates the levels it can even when one fails', function (): void {
        overdueRequest(EscalationAction::Escalate);
        $healthy = overdueRequest();

        Date::setTestNow('2026-07-28 10:01:00');

        $result = Approval::escalateOverdue();

        expect($result['escalated'])->toHaveCount(1)
            ->and($result['failed'])->toHaveCount(1)
            ->and($healthy->steps()->sole()->escalated_at)->not->toBeNull();
    });
});
