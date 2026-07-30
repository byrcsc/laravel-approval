<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Enums\RejectionPolicy;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Enums\StepState;
use ByRcsc\LaravelApproval\Facades\Approval;

it('keeps a two of three stage open after one rejection while approval remains reachable', function (): void {
    $first = user('First');
    $second = user('Second');
    $third = user('Third');
    $request = post()->submitForApproval(workflow([[
        'required_approvals' => 2,
        'approvers' => [$first, $second, $third],
    ]]), null, user('Requester'));

    Approval::reject($request, $first, 'I disagree.');

    expect($request->refresh()->state)->toBe(RequestState::Pending)
        ->and($request->steps()->sole()->state)->toBe(StepState::Active);

    Approval::approve($request, $second);
    Approval::approve($request, $third);

    expect($request->refresh()->state)->toBe(RequestState::Approved);
});

it('rejects when the minimum approvals become unreachable', function (): void {
    $first = user('First');
    $second = user('Second');
    $third = user('Third');
    $request = post()->submitForApproval(workflow([[
        'required_approvals' => 2,
        'approvers' => [$first, $second, $third],
    ]]), null, user('Requester'));

    Approval::reject($request, $first, 'No.');
    Approval::reject($request, $second, 'Also no.');

    expect($request->refresh()->state)->toBe(RequestState::Rejected)
        ->and($request->stageProgress()?->approvalsReceived)->toBe(0)
        ->and($request->stageProgress()?->rejectionsReceived)->toBe(2);
});

it('supports an explicit any rejection vetoes policy', function (): void {
    $first = user('First');
    $request = post()->submitForApproval(workflow([[
        'required_approvals' => 2,
        'rejection_policy' => RejectionPolicy::AnyRejectionVetoes,
        'approvers' => [$first, user('Second'), user('Third')],
    ]]), null, user('Requester'));

    Approval::reject($request, $first, 'Vetoed.');

    expect($request->refresh()->state)->toBe(RequestState::Rejected);
});

it('preserves the snapshotted rejection policy for the request lifetime', function (): void {
    $first = user('First');
    $workflow = workflow([[
        'required_approvals' => 2,
        'rejection_policy' => RejectionPolicy::AnyRejectionVetoes,
        'approvers' => [$first, user('Second'), user('Third')],
    ]]);
    $request = post()->submitForApproval($workflow, null, user('Requester'));

    $workflow->levels()->sole()->forceFill([
        'rejection_policy' => RejectionPolicy::ApprovalImpossible,
    ])->save();

    Approval::reject($request, $first, 'Vetoed under the snapshot.');

    expect($request->refresh()->state)->toBe(RequestState::Rejected)
        ->and($request->steps()->sole()->rejection_policy)->toBe(RejectionPolicy::AnyRejectionVetoes);
});

it('applies approval impossible behavior to one of many and unanimous stages', function (): void {
    $oneOfManyFirst = user('One of many first');
    $oneOfManySecond = user('One of many second');
    $oneOfMany = post()->submitForApproval(workflow([[
        'required_approvals' => 1,
        'approvers' => [$oneOfManyFirst, $oneOfManySecond],
    ]]), null, user('Requester'));

    Approval::reject($oneOfMany, $oneOfManyFirst, 'No.');
    expect($oneOfMany->refresh()->state)->toBe(RequestState::Pending);

    Approval::approve($oneOfMany, $oneOfManySecond);
    expect($oneOfMany->refresh()->state)->toBe(RequestState::Approved);

    $unanimousFirst = user('Unanimous first');
    $unanimous = post()->submitForApproval(workflow([[
        'required_approvals' => null,
        'approvers' => [$unanimousFirst, user('Unanimous second')],
    ]]), null, user('Requester'));

    Approval::reject($unanimous, $unanimousFirst, 'No.');

    expect($unanimous->refresh()->state)->toBe(RequestState::Rejected);
});
