<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Enums\StepState;
use ByRcsc\LaravelApproval\Exceptions\InvalidWorkflowException;
use ByRcsc\LaravelApproval\Tests\Stubs\AmountExceedsCondition;

it('evaluates conditions once and snapshots skipped levels visibly', function (): void {
    $finance = user('Finance');
    $workflow = workflow([
        [
            'condition' => AmountExceedsCondition::class,
            'condition_config' => ['amount' => 500_000],
            'required_approvals' => null,
            'approvers' => [],
        ],
        ['approvers' => [$finance]],
    ]);

    $request = purchaseOrder(amount: 100_000)->submitForApproval($workflow, ['amount' => 110_000], user('Author'));
    $steps = $request->steps()->get();

    expect($steps[0]->state)->toBe(StepState::Skipped)
        ->and($steps[0]->condition)->toBe(AmountExceedsCondition::class)
        ->and($steps[0]->condition_config)->toBe(['amount' => 500_000])
        ->and($steps[0]->condition_result)->toBeFalse()
        ->and($steps[0]->approverSlots)->toHaveCount(0)
        ->and($steps[1]->state)->toBe(StepState::Active)
        ->and($request->current_level_sequence)->toBe(2)
        ->and($request->actions()->where('action', ActionType::LevelSkipped->value)->count())->toBe(1);
});

it('includes a conditional level when the proposed draft meets its rule', function (): void {
    $cfo = user('CFO');
    $request = purchaseOrder(amount: 100_000)->submitForApproval(workflow([[
        'condition' => AmountExceedsCondition::class,
        'condition_config' => ['amount' => 500_000],
        'approvers' => [$cfo],
    ]]), ['amount' => 710_000], user('Author'));

    expect($request->steps()->sole()->state)->toBe(StepState::Active)
        ->and($request->steps()->sole()->condition_result)->toBeTrue();
});

it('auto-completes a request when every snapshotted level is skipped', function (): void {
    $order = purchaseOrder(amount: 100);
    $request = $order->submitForApproval(workflow([
        [
            'condition' => AmountExceedsCondition::class,
            'condition_config' => ['amount' => 500],
            'required_approvals' => null,
            'approvers' => [],
        ],
        [
            'condition' => AmountExceedsCondition::class,
            'condition_config' => ['amount' => 900],
            'required_approvals' => null,
            'approvers' => [],
        ],
    ]), ['amount' => 200], user('Author'));

    expect($request->refresh()->state)->toBe(RequestState::Approved)
        ->and($request->steps()->pluck('state')->all())->toBe([StepState::Skipped, StepState::Skipped])
        ->and($request->current_level_sequence)->toBe(2)
        ->and($order->fresh()?->amount)->toBe(200);
});

it('fails before writing when a condition class is invalid', function (): void {
    $workflow = workflow([[
        'condition' => stdClass::class,
        'approvers' => [user('Approver')],
    ]]);

    expect(fn () => purchaseOrder()->submitForApproval($workflow, null, user('Author')))
        ->toThrow(InvalidWorkflowException::class, 'does not implement')
        ->and($workflow->requests()->count())->toBe(0);
});
