<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Enums\StageStatus;
use ByRcsc\LaravelApproval\Exceptions\InvalidWorkflowException;
use ByRcsc\LaravelApproval\Tests\Stubs\AmountExceedsCondition;

it('snapshots a skipped stage with its condition, config, and result', function (): void {
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
    $stages = $request->stages()->get();

    expect($stages[0]->state)->toBe(StageStatus::Skipped)
        ->and($stages[0]->condition)->toBe(AmountExceedsCondition::class)
        ->and($stages[0]->condition_config)->toBe(['amount' => 500_000])
        ->and($stages[0]->condition_result)->toBeFalse()
        ->and($stages[0]->assignments)->toHaveCount(0)
        ->and($stages[1]->state)->toBe(StageStatus::Active)
        ->and($request->current_stage_sequence)->toBe(2)
        ->and($request->actions()->where('action', ActionType::StageSkipped->value)->count())->toBe(1);
});

it('includes a conditional stage when the proposed draft meets its rule', function (): void {
    $cfo = user('CFO');
    $request = purchaseOrder(amount: 100_000)->submitForApproval(workflow([[
        'condition' => AmountExceedsCondition::class,
        'condition_config' => ['amount' => 500_000],
        'approvers' => [$cfo],
    ]]), ['amount' => 710_000], user('Author'));

    expect($request->stages()->sole()->state)->toBe(StageStatus::Active)
        ->and($request->stages()->sole()->condition_result)->toBeTrue();
});

it('auto-completes a request when every snapshotted stage is skipped', function (): void {
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
        ->and($request->stages()->pluck('state')->all())->toBe([StageStatus::Skipped, StageStatus::Skipped])
        ->and($request->current_stage_sequence)->toBe(2)
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
