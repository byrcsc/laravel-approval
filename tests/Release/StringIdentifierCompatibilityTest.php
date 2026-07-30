<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Tests\Stubs\Post;
use ByRcsc\LaravelApproval\Tests\Stubs\UlidUser;
use ByRcsc\LaravelApproval\Tests\Stubs\UuidUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

it('runs the complete decision path with UUID and ULID users and morph maps', function (string $alias, string $class): void {
    $this->rebootWith([
        'approval.user_model' => $class,
        'approval.user_key_type' => str_starts_with($alias, 'uuid') ? 'uuid' : 'ulid',
    ]);

    Relation::enforceMorphMap([
        'post' => Post::class,
        $alias => $class,
    ]);

    /** @var Model $requester */
    $requester = $class::create(['name' => 'Requester']);
    /** @var Model $approver */
    $approver = $class::create(['name' => 'Approver']);
    /** @var Model $delegate */
    $delegate = $class::create(['name' => 'Delegate']);

    $flow = workflow([['approvers' => [$approver]]]);
    $request = post()->submitForApproval($flow, null, $requester);

    expect(Approval::eligibility($request, $approver)->eligible)->toBeTrue()
        ->and(Approval::delegationEligibility($request, $approver)->eligible)->toBeTrue()
        ->and(Approval::awaiting($approver)->sole()->is($request))->toBeTrue();

    Approval::delegate($request, $approver, $delegate);

    expect(Approval::awaiting($delegate)->sole()->is($request))->toBeTrue();

    Approval::approve($request, $delegate);

    expect($request->refresh()->state)->toBe(RequestState::Approved)
        ->and($request->actions()->where('action', 'approved')->sole()->actor_id)
        ->toBe((string) $delegate->getKey());

    $rejected = post('Rejected')->submitForApproval($flow, null, $requester);
    Approval::reject($rejected, $approver, 'Not acceptable');

    expect($rejected->refresh()->state)->toBe(RequestState::Rejected)
        ->and($rejected->actions()->where('action', 'rejected')->sole()->actor_id)
        ->toBe((string) $approver->getKey());
})->with([
    ['uuid-user', UuidUser::class],
    ['ulid-user', UlidUser::class],
]);
