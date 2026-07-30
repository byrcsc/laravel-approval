<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Enums\ConflictResolution;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Exceptions\AlreadyDecidedException;
use ByRcsc\LaravelApproval\Exceptions\ApprovalInProgressException;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Tests\Stubs\Post;

/**
 * @return array{ApprovalRequest, Post}
 */
function unresolvedConflict(): array
{
    $approver = user('Conflict approver');
    $post = post('Original');
    $request = $post->submitForApproval(
        workflow([['approvers' => [$approver]]]),
        ['title' => 'Approved edit'],
        user('Requester'),
    );

    $post->forceFill(['title' => 'Concurrent edit'])->save();
    Approval::approve($request, $approver);

    return [$request->refresh(), $post];
}

it('keeps a conflicted request unresolved without a completion timestamp', function (): void {
    [$request, $post] = unresolvedConflict();

    expect($request->state)->toBe(RequestState::Conflicted)
        ->and($request->completed_at)->toBeNull()
        ->and(Approval::pendingFor($post)?->is($request))->toBeTrue()
        ->and($post->isPendingApproval())->toBeTrue();
});

it('blocks an unrelated submission while a conflict is unresolved', function (): void {
    [$request, $post] = unresolvedConflict();

    expect(fn () => $post->submitForApproval($request->workflow, null, user('Other requester')))
        ->toThrow(ApprovalInProgressException::class);

    expect(ApprovalRequest::query()->forApprovable($post)->unresolved()->count())->toBe(1);
});

it('refuses to reapply over another unresolved request', function (): void {
    [$request, $post] = unresolvedConflict();

    approvalRequest($post, user('Other requester'), [
        'workflow_id' => $request->workflow_id,
        'state' => RequestState::Pending,
    ]);

    expect(fn () => Approval::resolve($request, ConflictResolution::Reapply, user('Admin')))
        ->toThrow(ApprovalInProgressException::class);

    expect($post->fresh()?->title)->toBe('Concurrent edit');
});

it('atomically supersedes a conflict and audits both sides of its replacement', function (): void {
    [$conflicted] = unresolvedConflict();

    $replacement = Approval::resubmit($conflicted, ['title' => 'Replacement']);

    expect($conflicted->refresh()->state)->toBe(RequestState::Superseded)
        ->and($conflicted->completed_at)->not->toBeNull()
        ->and($replacement->state)->toBe(RequestState::Pending)
        ->and($replacement->resubmittedFrom?->is($conflicted))->toBeTrue()
        ->and($conflicted->resubmissions()->sole()->is($replacement))->toBeTrue()
        ->and($conflicted->actions()->where('action', 'superseded')->sole()->metadata)
        ->toBe(['replacement' => $replacement->ulid])
        ->and($replacement->actions()->where('action', 'resubmitted')->sole()->metadata)
        ->toBe(['resubmitted_from' => $conflicted->ulid]);
});

it('serializes competing conflict replacements into one unresolved request', function (): void {
    [$conflicted, $post] = unresolvedConflict();
    $stale = ApprovalRequest::query()->findOrFail($conflicted->getKey());

    Approval::resubmit($conflicted, ['title' => 'Winner']);

    expect(fn () => Approval::resubmit($stale, ['title' => 'Loser']))
        ->toThrow(AlreadyDecidedException::class)
        ->and(ApprovalRequest::query()->forApprovable($post)->unresolved()->count())->toBe(1);
});
