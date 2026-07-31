<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Data\AttachmentEvidence;
use ByRcsc\LaravelApproval\Data\RevisedDraft;
use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Models\ApprovalAction;
use ByRcsc\LaravelApproval\Tests\Stubs\AutoPurchaseOrder;
use ByRcsc\LaravelApproval\Tests\Stubs\Post;
use ByRcsc\LaravelApproval\Tests\Stubs\PurchaseOrder;

/**
 * The guarantees the README states, asserted against the engine so the
 * documentation cannot drift away from the behavior.
 */
it('changes only request state and history when a record-level request is approved', function (): void {
    $approver = user('Approver');
    $post = post('Unchanged title');
    $post->forceFill(['published' => false])->save();

    $request = $post->submitForApproval(workflow([['approvers' => [$approver]]]), null, user('Requester'));
    $before = $post->getAttributes();

    Approval::approve($request, $approver);

    $after = $post->fresh();

    expect($request->refresh()->state)->toBe(RequestState::Approved)
        ->and($request->completed_at)->not->toBeNull()
        ->and($request->actions()->where('action', ActionType::Approved->value)->exists())->toBeTrue()
        // Not hidden, activated, published, reverted, or deleted.
        ->and($after)->not->toBeNull()
        ->and($after?->getAttribute('title'))->toBe($before['title'])
        ->and($after?->getAttribute('published'))->toBe($before['published'])
        ->and($after?->getAttribute('deleted_at'))->toBeNull()
        ->and(Post::query()->whereKey($post->getKey())->exists())->toBeTrue();
});

it('holds drafted attributes until the final approval and then applies them', function (): void {
    $first = user('First');
    $second = user('Second');
    $post = post('Original title');

    $request = $post->submitForApproval(
        workflow([['approvers' => [$first]], ['approvers' => [$second]]]),
        ['title' => 'Approved title'],
        user('Requester'),
    );

    expect($post->fresh()?->title)->toBe('Original title')
        ->and($post->pendingDraft()?->new)->toBe(['title' => 'Approved title']);

    Approval::approve($request, $first);

    expect($post->fresh()?->title)->toBe('Original title');

    Approval::approve($request, $second);

    expect($post->fresh()?->title)->toBe('Approved title')
        ->and($request->refresh()->state)->toBe(RequestState::Approved);
});

it('creates the request after the record is persisted rather than gating creation', function (): void {
    $requester = user('Requester');
    PurchaseOrder::$actingRequester = $requester;
    workflow([['approvers' => [user('Approver')]]], ['slug' => PurchaseOrder::$workflowSlug]);

    $order = AutoPurchaseOrder::create(['reference' => 'AUTO-1', 'amount' => 100]);

    expect($order->exists)->toBeTrue()
        ->and(AutoPurchaseOrder::query()->whereKey($order->getKey())->exists())->toBeTrue()
        ->and($order->approvalRequests()->count())->toBe(1)
        ->and($order->pendingApprovalRequest()?->state)->toBe(RequestState::Pending)
        ->and($order->fresh()?->getAttribute('status'))->toBe('draft');
});

it('gives a conflicted request no completion timestamp and no approved action', function (): void {
    $approver = user('Approver');
    $post = post('Original');
    $request = $post->submitForApproval(
        workflow([['approvers' => [$approver]]]),
        ['title' => 'Approved edit'],
        user('Requester'),
    );

    $post->forceFill(['title' => 'Concurrent edit'])->save();
    Approval::approve($request, $approver);

    expect($request->refresh()->state)->toBe(RequestState::Conflicted)
        ->and($request->completed_at)->toBeNull()
        ->and($post->fresh()?->title)->toBe('Concurrent edit')
        ->and($request->actions()->where('action', ActionType::Applied->value)->exists())->toBeFalse()
        ->and($request->actions()->where('action', ActionType::Conflicted->value)->exists())->toBeTrue();
});

it('walks the hash chain without reading stored file bytes', function (): void {
    $approver = user('Approver');
    $request = post()->submitForApproval(workflow([['approvers' => [$approver]]]), null, user('Requester'));

    Approval::attach(new AttachmentEvidence(
        request: $request,
        disk: 'a-disk-that-does-not-exist',
        path: 'a/path/that/is/not/there.pdf',
        checksum: str_repeat('f', 64),
    ));

    // A clean result despite the file being absent: verification covers the
    // recorded metadata, never the stored bytes.
    expect(Approval::verifyTrail($request))->toBe([]);

    // Editing a recorded action is what a clean result does rule out.
    ApprovalAction::query()
        ->whereKey($request->actions()->first()?->getKey())
        ->update(['comment' => 'Rewritten after the fact']);

    expect(Approval::verifyTrail($request))->not->toBe([]);
});

it('creates a new linked request rather than reopening a returned one', function (): void {
    $approver = user('Approver');
    $requester = user('Requester');
    $post = post('Original');
    $request = $post->submitForApproval(
        workflow([['approvers' => [$approver]]]),
        ['title' => 'First attempt'],
        $requester,
    );

    Approval::returnForRevision($request, $approver, 'Use the agreed title.');

    $replacement = Approval::reviseAndResubmit(
        $request->refresh(),
        RevisedDraft::withAttributes(['title' => 'Second attempt']),
    );

    expect($request->refresh()->state)->toBe(RequestState::Returned)
        ->and($replacement->is($request))->toBeFalse()
        ->and($replacement->state)->toBe(RequestState::Pending)
        ->and($replacement->resubmitted_from_id)->toBe($request->getKey())
        ->and($replacement->currentStage()?->sequence)->toBe(1)
        ->and($post->pendingDraft()?->new)->toBe(['title' => 'Second attempt']);
});
