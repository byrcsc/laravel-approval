<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Enums\StageStatus;
use ByRcsc\LaravelApproval\Facades\Approval;

it('reports incomplete stage progress', function (): void {
    $one = user('One');
    $two = user('Two');
    $three = user('Three');
    $request = post()->submitForApproval(
        workflow([['required_approvals' => 2, 'approvers' => [$one, $two, $three]]]),
        null,
        user('Requester'),
    );

    Approval::approve($request, $one);

    $progress = $request->refresh()->stageProgress();

    expect($progress)->not->toBeNull()
        ->and($progress?->assignedApprovers)->toBe(3)
        ->and($progress?->decisionsRecorded)->toBe(1)
        ->and($progress?->approvalsReceived)->toBe(1)
        ->and($progress?->approvalsRequired)->toBe(2)
        ->and($progress?->remainingApprovals)->toBe(1)
        ->and($progress?->rejectionsReceived)->toBe(0)
        ->and($progress?->status)->toBe(StageStatus::Active)
        ->and($progress?->isSatisfied())->toBeFalse();
});

it('reports satisfied stage progress using the routing result', function (): void {
    $one = user('One');
    $two = user('Two');
    $request = post()->submitForApproval(
        workflow([['required_approvals' => 2, 'approvers' => [$one, $two]]]),
        null,
        user('Requester'),
    );

    Approval::approve($request, $one);
    Approval::approve($request, $two);

    $progress = $request->refresh()->stageProgress();

    expect($progress)->not->toBeNull()
        ->and($progress?->decisionsRecorded)->toBe(2)
        ->and($progress?->approvalsReceived)->toBe(2)
        ->and($progress?->remainingApprovals)->toBe(0)
        ->and($progress?->rejectionsReceived)->toBe(0)
        ->and($progress?->status)->toBe(StageStatus::Approved)
        ->and($progress?->isSatisfied())->toBeTrue();
});

it('reports rejected stage progress', function (): void {
    $one = user('One');
    $request = post()->submitForApproval(
        workflow([['required_approvals' => 2, 'approvers' => [$one, user('Two'), user('Three')]]]),
        null,
        user('Requester'),
    );

    Approval::reject($request, $one, 'The request is incomplete.');

    $progress = $request->refresh()->stageProgress();

    expect($progress)->not->toBeNull()
        ->and($progress?->assignedApprovers)->toBe(3)
        ->and($progress?->decisionsRecorded)->toBe(1)
        ->and($progress?->approvalsReceived)->toBe(0)
        ->and($progress?->approvalsRequired)->toBe(2)
        ->and($progress?->remainingApprovals)->toBe(2)
        ->and($progress?->rejectionsReceived)->toBe(1)
        ->and($progress?->status)->toBe(StageStatus::Rejected)
        ->and($progress?->isSatisfied())->toBeFalse();
});
