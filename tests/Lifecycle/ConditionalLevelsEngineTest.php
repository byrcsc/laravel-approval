<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Enums\StepState;
use ByRcsc\LaravelApproval\Events\ApprovalCompleted;
use ByRcsc\LaravelApproval\Events\ApprovalSubmitted;
use ByRcsc\LaravelApproval\Events\DraftApplied;
use ByRcsc\LaravelApproval\Exceptions\InvalidWorkflowException;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Models\ApprovalAction;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStep;
use ByRcsc\LaravelApproval\Tests\Stubs\AmountExceedsCondition;
use ByRcsc\LaravelApproval\Tests\Stubs\MeddlingCondition;
use ByRcsc\LaravelApproval\Tests\Stubs\ScriptedCondition;
use ByRcsc\LaravelApproval\Tests\Stubs\ThresholdFromConfigCondition;
use ByRcsc\LaravelApproval\Tests\Stubs\WitnessResolver;
use Illuminate\Support\Facades\Event;

// How conditional levels behave against the rest of the engine: the
// all-skipped completion path, throwing and misconfigured conditions,
// container resolution, resync, escalation, and resume's re-evaluation.

it('completes an all-skipped submission with coherent events and audit trail', function (): void {
    Event::fake([ApprovalSubmitted::class, ApprovalCompleted::class, DraftApplied::class]);

    $order = purchaseOrder(amount: 100);
    $request = $order->submitForApproval(workflow([
        ['condition' => AmountExceedsCondition::class, 'condition_config' => ['amount' => 500], 'required_approvals' => null, 'approvers' => []],
        ['condition' => AmountExceedsCondition::class, 'condition_config' => ['amount' => 900], 'required_approvals' => null, 'approvers' => []],
    ]), ['amount' => 300], user('Author'));

    Event::assertDispatched(ApprovalSubmitted::class, fn (ApprovalSubmitted $event): bool => $event->step === null);
    Event::assertDispatched(ApprovalCompleted::class);
    Event::assertDispatched(DraftApplied::class);

    $actions = $request->actions()->pluck('action')->all();

    expect($request->refresh()->state)->toBe(RequestState::Approved)
        ->and($order->fresh()?->amount)->toBe(300)
        ->and($actions)->toBe([
            ActionType::Submitted,
            ActionType::LevelSkipped,
            ActionType::LevelSkipped,
            ActionType::Applied,
        ]);
});

it('completes an all-skipped record-gating submission without an apply line', function (): void {
    Event::fake([ApprovalCompleted::class, DraftApplied::class]);

    $request = purchaseOrder(amount: 100)->submitForApproval(workflow([[
        'condition' => AmountExceedsCondition::class,
        'condition_config' => ['amount' => 500],
        'required_approvals' => null,
        'approvers' => [],
    ]]), null, user('Author'));

    Event::assertDispatched(ApprovalCompleted::class);
    Event::assertNotDispatched(DraftApplied::class);

    expect($request->refresh()->state)->toBe(RequestState::Approved)
        ->and($request->actions()->where('action', ActionType::Applied->value)->count())->toBe(0)
        ->and($request->steps()->sole()->state)->toBe(StepState::Skipped);
});

it('sends an all-skipped submission through the stale check, not around it', function (): void {
    $order = purchaseOrder(amount: 500);

    // The condition edits the record after the draft's originals were
    // snapshotted, then skips its level. The submission's own apply must see
    // that as drift and conflict rather than silently overwrite the edit.
    $request = $order->submitForApproval(workflow([[
        'condition' => MeddlingCondition::class,
        'condition_config' => ['set' => 999],
        'required_approvals' => null,
        'approvers' => [],
    ]]), ['amount' => 700], user('Author'));

    $conflicted = $request->actions()->where('action', ActionType::Conflicted->value)->sole();

    expect($request->refresh()->state)->toBe(RequestState::Conflicted)
        ->and($order->fresh()?->amount)->toBe(999)
        ->and($conflicted->metadata)->toBe(['reason' => 'stale_attributes', 'attributes' => ['amount']]);
});

it('rolls back a submission whole when a later condition throws', function (): void {
    ScriptedCondition::$throwMessage = 'The org chart service is down.';

    $workflow = workflow([
        ['approvers' => [user('First')]],
        ['condition' => ScriptedCondition::class, 'approvers' => [user('Second')]],
    ]);

    expect(fn () => purchaseOrder()->submitForApproval($workflow, ['amount' => 900], user('Author')))
        ->toThrow(RuntimeException::class, 'The org chart service is down.')
        ->and(ApprovalRequest::query()->count())->toBe(0)
        ->and(ApprovalRequestStep::query()->count())->toBe(0)
        ->and(ApprovalAction::query()->count())->toBe(0);
});

it('fails before writing when a condition class does not exist', function (): void {
    $workflow = workflow([[
        'condition' => 'App\\Conditions\\Vanished',
        'approvers' => [user('Approver')],
    ]]);

    expect(fn () => purchaseOrder()->submitForApproval($workflow, null, user('Author')))
        ->toThrow(InvalidWorkflowException::class, 'does not exist')
        ->and($workflow->requests()->count())->toBe(0);
});

it('resolves conditions from the container with constructor dependencies', function (): void {
    config()->set('approval-tests.threshold', 500);

    $request = purchaseOrder(amount: 100)->submitForApproval(workflow([[
        'condition' => ThresholdFromConfigCondition::class,
        'approvers' => [user('Approver')],
    ]]), ['amount' => 600], user('Author'));

    expect($request->steps()->sole()->state)->toBe(StepState::Active);

    config()->set('approval-tests.threshold', 1_000);

    $second = purchaseOrder('PO-1002', 100)->submitForApproval(workflow([[
        'condition' => ThresholdFromConfigCondition::class,
        'required_approvals' => null,
        'approvers' => [],
    ]]), ['amount' => 600], user('Author'));

    expect($second->steps()->sole()->state)->toBe(StepState::Skipped);
});

it('evaluates a condition exactly once for the life of a request', function (): void {
    $approver = user('Approver');
    $request = purchaseOrder()->submitForApproval(workflow([[
        'condition' => ScriptedCondition::class,
        'approvers' => [$approver],
    ]]), ['amount' => 900], user('Author'));

    expect(ScriptedCondition::$evaluations)->toBe(1);

    Approval::approve($request, $approver);

    expect($request->refresh()->state)->toBe(RequestState::Approved)
        ->and(ScriptedCondition::$evaluations)->toBe(1);
});

it('judges the proposal on a clone, leaving the caller instance untouched', function (): void {
    $order = purchaseOrder(amount: 100);

    $order->submitForApproval(workflow([[
        'condition' => AmountExceedsCondition::class,
        'condition_config' => ['amount' => 500],
        'approvers' => [user('Approver')],
    ]]), ['amount' => 600], user('Author'));

    expect($order->amount)->toBe(100)
        ->and($order->isDirty())->toBeFalse()
        ->and($order->fresh()?->amount)->toBe(100);
});

it('shows conditions the draft but resolvers the stored record', function (): void {
    WitnessResolver::$approvers = [user('Resolved Approver')];

    $request = purchaseOrder(amount: 100)->submitForApproval(workflow([[
        'condition' => AmountExceedsCondition::class,
        'condition_config' => ['amount' => 500],
        'approvers' => [['resolver' => WitnessResolver::class]],
    ]]), ['amount' => 600], user('Author'));

    // The condition passed on the proposed 600; the resolver was asked about
    // the record as it stands, which is still 100.
    expect($request->steps()->sole()->state)->toBe(StepState::Active)
        ->and(WitnessResolver::$sawAmount)->toBe(100);
});

it('still fails a passing conditional level that resolves no approvers', function (): void {
    $workflow = workflow([[
        'condition' => AmountExceedsCondition::class,
        'condition_config' => ['amount' => 500],
        'approvers' => [],
    ]]);

    expect(fn () => purchaseOrder(amount: 100)->submitForApproval($workflow, ['amount' => 600], user('Author')))
        ->toThrow(InvalidWorkflowException::class, 'resolved to no approvers')
        ->and($workflow->requests()->count())->toBe(0);
});

it('advances over a skipped middle level and completes past a skipped tail', function (): void {
    [$first, $third] = [user('First'), user('Third')];
    $order = purchaseOrder(amount: 100);
    $request = $order->submitForApproval(workflow([
        ['approvers' => [$first]],
        ['condition' => AmountExceedsCondition::class, 'condition_config' => ['amount' => 500], 'required_approvals' => null, 'approvers' => []],
        ['approvers' => [$third]],
        ['condition' => AmountExceedsCondition::class, 'condition_config' => ['amount' => 900], 'required_approvals' => null, 'approvers' => []],
    ]), ['amount' => 300], user('Author'));

    Approval::approve($request, $first);

    expect($request->refresh()->current_level_sequence)->toBe(3)
        ->and($request->steps()->where('sequence', 2)->sole()->state)->toBe(StepState::Skipped);

    Approval::approve($request, $third);

    expect($request->refresh()->state)->toBe(RequestState::Approved)
        ->and($order->fresh()?->amount)->toBe(300)
        ->and($request->actions()->where('action', ActionType::LevelSkipped->value)->count())->toBe(2);
});

it('does not resurrect a skipped step on resync, even after the workflow changes', function (): void {
    $approver = user('Approver');
    $late = user('Late Addition');
    $workflow = workflow([
        ['condition' => AmountExceedsCondition::class, 'condition_config' => ['amount' => 500], 'required_approvals' => null, 'approvers' => []],
        ['approvers' => [$approver]],
    ]);

    $request = purchaseOrder(amount: 100)->submitForApproval($workflow, ['amount' => 300], user('Author'));

    // The workflow moves on: the condition is dropped and the retired level
    // gains people. None of that may reach the snapshotted, already-skipped step.
    $firstLevel = $workflow->levels()->where('sequence', 1)->sole();
    $firstLevel->forceFill(['condition' => null, 'condition_config' => null])->save();
    approverRow($firstLevel, user('New Gatekeeper'));
    approverRow($workflow->levels()->where('sequence', 2)->sole(), $late);

    $changes = Approval::resync($request, prune: true);

    $skipped = $request->steps()->where('sequence', 1)->sole();

    expect($changes)->not->toHaveKey(1)
        ->and($changes[2]['added'])->toHaveCount(1)
        ->and($skipped->state)->toBe(StepState::Skipped)
        ->and($skipped->approverSlots()->count())->toBe(0)
        ->and($request->refresh()->current_level_sequence)->toBe(2);
});

it('never escalates or strands a skipped step, whatever its SLA says', function (): void {
    $request = purchaseOrder(amount: 100)->submitForApproval(workflow([
        [
            'condition' => AmountExceedsCondition::class,
            'condition_config' => ['amount' => 500],
            'sla_minutes' => 30,
            'remind_every_minutes' => 10,
            'required_approvals' => null,
            'approvers' => [],
        ],
        ['approvers' => [user('Approver')]],
    ]), ['amount' => 300], user('Author'));

    $skipped = $request->steps()->where('sequence', 1)->sole();

    expect($skipped->deadline_at)->toBeNull();

    $this->travel(2)->hours();

    $sweep = Approval::escalateOverdue();

    expect($sweep['escalated'])->toBe([])
        ->and($sweep['reminded'])->toBe([])
        ->and($sweep['stranded'])->toBe([])
        ->and($skipped->refresh()->escalated_at)->toBeNull()
        ->and($skipped->stranded_at)->toBeNull();
});

it('re-evaluates conditions on resume against the amended draft', function (): void {
    [$first, $cfo] = [user('First'), user('CFO')];
    $workflow = workflow([
        ['approvers' => [$first]],
        ['condition' => AmountExceedsCondition::class, 'condition_config' => ['amount' => 500], 'approvers' => [$cfo]],
    ]);

    $returned = purchaseOrder(amount: 100)->submitForApproval($workflow, ['amount' => 900], user('Author'));

    expect($returned->steps()->where('sequence', 2)->sole()->state)->toBe(StepState::Queued);

    Approval::returnToSubmitter($returned, $first, 'Trim the total.');

    $replacement = Approval::resume($returned, ['amount' => 300]);

    expect($replacement->steps()->where('sequence', 2)->sole()->state)->toBe(StepState::Skipped)
        ->and($replacement->steps()->where('sequence', 1)->sole()->state)->toBe(StepState::Active);
});

it('wakes a previously skipped level when the resumed draft crosses its rule', function (): void {
    [$first, $cfo] = [user('First'), user('CFO')];
    $workflow = workflow([
        ['approvers' => [$first]],
        ['condition' => AmountExceedsCondition::class, 'condition_config' => ['amount' => 500], 'approvers' => [$cfo]],
    ]);

    $returned = purchaseOrder(amount: 100)->submitForApproval($workflow, ['amount' => 300], user('Author'));

    expect($returned->steps()->where('sequence', 2)->sole()->state)->toBe(StepState::Skipped);

    Approval::returnToSubmitter($returned, $first, 'This needs to cover the full order.');

    $replacement = Approval::resume($returned, ['amount' => 900]);
    $woken = $replacement->steps()->where('sequence', 2)->sole();

    expect($woken->state)->toBe(StepState::Queued)
        ->and($woken->approverSlots()->count())->toBe(1)
        ->and((string) $woken->approverSlots()->sole()->approver_id)->toBe((string) $cfo->getKey());
});

it('resumes straight to approval when the amended draft skips every level', function (): void {
    $cfo = user('CFO');
    $order = purchaseOrder(amount: 100);
    $returned = $order->submitForApproval(workflow([[
        'condition' => AmountExceedsCondition::class,
        'condition_config' => ['amount' => 500],
        'approvers' => [$cfo],
    ]]), ['amount' => 900], user('Author'));

    Approval::returnToSubmitter($returned, $cfo, 'Take it under the threshold and it can go through.');

    $replacement = Approval::resume($returned, ['amount' => 300]);

    expect($replacement->refresh()->state)->toBe(RequestState::Approved)
        ->and($replacement->steps()->sole()->state)->toBe(StepState::Skipped)
        ->and($replacement->resubmittedFrom?->is($returned))->toBeTrue()
        ->and($replacement->actions()->where('action', ActionType::Resumed->value)->count())->toBe(1)
        ->and($order->fresh()?->amount)->toBe(300);
});

it('rolls back a resume whole when a condition throws', function (): void {
    $approver = user('Approver');
    $returned = purchaseOrder()->submitForApproval(workflow([[
        'condition' => ScriptedCondition::class,
        'approvers' => [$approver],
    ]]), ['amount' => 900], user('Author'));

    Approval::returnToSubmitter($returned, $approver, 'Fix it.');

    ScriptedCondition::$throwMessage = 'The org chart service is down.';

    expect(fn () => Approval::resume($returned))
        ->toThrow(RuntimeException::class)
        ->and($returned->refresh()->state)->toBe(RequestState::Returned)
        ->and(ApprovalRequest::query()->count())->toBe(1);
});
