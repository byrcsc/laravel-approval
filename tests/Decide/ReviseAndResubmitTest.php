<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Data\RevisedDraft;
use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Enums\StageStatus;
use ByRcsc\LaravelApproval\Exceptions\AlreadyDecidedException;
use ByRcsc\LaravelApproval\Exceptions\CommentRequiredException;
use ByRcsc\LaravelApproval\Exceptions\InvalidDraftException;
use ByRcsc\LaravelApproval\Exceptions\UnauthorizedApproverException;
use ByRcsc\LaravelApproval\Facades\Approval;

it('returns a request to its requester without rewriting prior approvals', function (): void {
    [$first, $second, $third] = [user('First'), user('Second'), user('Third')];
    $author = user('Author');
    $order = purchaseOrder();
    $request = $order->submitForApproval(workflow([
        ['approvers' => [$first]],
        ['approvers' => [$second]],
        ['approvers' => [$third]],
    ]), ['amount' => 900], $author);

    Approval::approve($request, $first);
    Approval::returnForRevision($request, $second, 'Correct the total.');

    expect($request->refresh()->state)->toBe(RequestState::Returned)
        ->and($request->stages()->pluck('state')->all())->toBe([
            StageStatus::Approved,
            StageStatus::Returned,
            StageStatus::Skipped,
        ])
        ->and($request->actions()->where('action', ActionType::Approved->value)->count())->toBe(1)
        ->and($order->fresh()?->amount)->toBe(500);
});

it('requires a reason and an eligible active approver to return', function (): void {
    $approver = user('Approver');
    $request = post()->submitForApproval(workflow([['approvers' => [$approver]]]), null, user('Author'));

    expect(fn () => Approval::returnForRevision($request, $approver, ''))
        ->toThrow(CommentRequiredException::class)
        ->and(fn () => Approval::returnForRevision($request, user('Stranger'), 'Send it back.'))
        ->toThrow(UnauthorizedApproverException::class)
        ->and($request->refresh()->state)->toBe(RequestState::Pending);
});

it('revises a returned request into a linked replacement at the first stage', function (): void {
    $first = user('First');
    $second = user('Second');
    $author = user('Author');
    $workflow = workflow([
        ['approvers' => [$first]],
        ['approvers' => [$second]],
    ]);
    $order = purchaseOrder();
    $returned = $order->submitForApproval($workflow, ['reference' => 'PO-NEW', 'amount' => 900], $author);

    Approval::approve($returned, $first);
    Approval::returnForRevision($returned, $second, 'Correct the total.');

    $replacement = Approval::reviseAndResubmit(
        $returned,
        RevisedDraft::withAttributes(['reference' => 'PO-NEW', 'amount' => 750]),
    );

    expect($replacement->state)->toBe(RequestState::Pending)
        ->and($replacement->current_stage_sequence)->toBe(1)
        ->and(canonical($replacement->new_attributes))->toBe(canonical(['reference' => 'PO-NEW', 'amount' => 750]))
        ->and($replacement->resubmittedFrom?->is($returned))->toBeTrue()
        ->and($returned->refresh()->state)->toBe(RequestState::Returned)
        ->and($replacement->actions()->where('action', ActionType::Resubmitted->value)->count())->toBe(1);
});

it('lets a revised draft add attributes the model allows', function (): void {
    $approver = user('Approver');
    $request = purchaseOrder()->submitForApproval(
        workflow([['approvers' => [$approver]]]),
        ['amount' => 900],
        user('Author'),
    );
    Approval::returnForRevision($request, $approver, 'Name the purchase order.');

    $replacement = Approval::reviseAndResubmit(
        $request,
        RevisedDraft::withAttributes(['reference' => 'PO-X', 'amount' => 750]),
    );

    expect(canonical($replacement->new_attributes))
        ->toBe(canonical(['reference' => 'PO-X', 'amount' => 750]));
});

it('rejects revised attributes outside the model allow-list without touching the prior request', function (): void {
    $approver = user('Approver');
    $request = purchaseOrder()->submitForApproval(
        workflow([['approvers' => [$approver]]]),
        ['amount' => 900],
        user('Author'),
    );
    Approval::returnForRevision($request, $approver, 'Correct it.');

    expect(fn () => Approval::reviseAndResubmit($request, RevisedDraft::withAttributes(['status' => 'paid'])))
        ->toThrow(InvalidDraftException::class, 'status')
        ->and($request->refresh()->state)->toBe(RequestState::Returned)
        ->and($request->resubmissions()->count())->toBe(0);
});

it('does not revise requests in a state that forbids it', function (): void {
    $request = post()->submitForApproval(
        workflow([['approvers' => [user('Approver')]]]),
        null,
        user('Author'),
    );

    expect(fn () => Approval::reviseAndResubmit($request, RevisedDraft::inherit()))
        ->toThrow(AlreadyDecidedException::class, 'cannot be revised and resubmitted');
});
