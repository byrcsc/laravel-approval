<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Exceptions\InvalidDraftException;
use ByRcsc\LaravelApproval\Tests\Stubs\PurchaseOrder;

it('gates the record itself when no draft is given', function (): void {
    $workflow = workflow([['approvers' => [user()]]]);

    $request = post()->submitForApproval($workflow, null, user());

    expect($request->getAttributeValue('new_attributes'))->toBeNull()
        ->and($request->getAttributeValue('original_attributes'))->toBeNull();
});

it('stores the draft alongside what it was a draft of', function (): void {
    $post = post('Original title');
    $workflow = workflow([['approvers' => [user()]]]);

    $request = $post->submitForApproval($workflow, ['title' => 'Revised title'], user());

    expect($request->getAttributeValue('new_attributes'))->toBe(['title' => 'Revised title'])
        ->and($request->getAttributeValue('original_attributes'))->toBe(['title' => 'Original title']);
});

it('snapshots only the attributes the draft touches', function (): void {
    $post = post('Original title');
    $workflow = workflow([['approvers' => [user()]]]);

    $request = $post->submitForApproval($workflow, ['title' => 'Revised'], user());

    expect(array_keys((array) $request->getAttributeValue('original_attributes')))->toBe(['title']);
});

it('snapshots what the database holds, not an unsaved change in memory', function (): void {
    $post = post('Persisted title');
    $workflow = workflow([['approvers' => [user()]]]);

    // Somebody changed the model in memory without saving it.
    $post->title = 'Never saved';

    $request = $post->submitForApproval($workflow, ['title' => 'Revised'], user());

    expect($request->getAttributeValue('original_attributes'))->toBe(['title' => 'Persisted title']);
});

it('leaves the record untouched while the draft waits', function (): void {
    $post = post('Original title');
    $workflow = workflow([['approvers' => [user()]]]);

    $post->submitForApproval($workflow, ['title' => 'Revised title'], user());

    expect($post->fresh()?->getAttributeValue('title'))->toBe('Original title');
});

it('refuses an empty draft rather than reading it as none', function (): void {
    $workflow = workflow([['approvers' => [user()]]]);

    expect(fn () => post()->submitForApproval($workflow, [], user()))
        ->toThrow(InvalidDraftException::class, 'Pass null to gate the record itself');
});

it('accepts anything when the model declares no allow-list', function (): void {
    $post = post();
    $workflow = workflow([['approvers' => [user()]]]);

    $request = $post->submitForApproval($workflow, ['title' => 'New', 'published' => true], user());

    expect(array_keys((array) $request->getAttributeValue('new_attributes')))->toBe(['title', 'published']);
});

it('refuses an attribute the model does not offer for drafting', function (): void {
    $order = purchaseOrder();
    workflow([['approvers' => [user()]]], ['slug' => 'purchase-order']);

    expect(fn () => $order->submitForApproval(null, ['amount' => 900, 'status' => 'approved'], user()))
        ->toThrow(InvalidDraftException::class, 'does not accept status as a draftable attribute');
});

it('names every rejected attribute at once', function (): void {
    $order = purchaseOrder();
    workflow([['approvers' => [user()]]], ['slug' => 'purchase-order']);

    expect(fn () => $order->submitForApproval(null, ['status' => 'x', 'created_at' => 'y'], user()))
        ->toThrow(InvalidDraftException::class, 'does not accept status, created_at as draftable attributes');
});

it('accepts the attributes the model does offer', function (): void {
    $order = purchaseOrder('PO-1001', 500);
    workflow([['approvers' => [user()]]], ['slug' => 'purchase-order']);

    $request = $order->submitForApproval(null, ['amount' => 900], user());

    expect($request->getAttributeValue('new_attributes'))->toBe(['amount' => 900])
        ->and($request->getAttributeValue('original_attributes'))->toBe(['amount' => 500]);
});

it('reads the pending draft back off the model', function (): void {
    $order = purchaseOrder();
    workflow([['approvers' => [user()]]], ['slug' => 'purchase-order']);

    expect($order->pendingDraft())->toBeNull();

    $order->submitForApproval(null, ['amount' => 900], user());

    expect($order->pendingDraft()?->new)->toBe(['amount' => 900])
        ->and($order->pendingDraft()?->original)->toBe(['amount' => 500]);
});

it('reads a null draft for a record gated as a whole', function (): void {
    $order = purchaseOrder();
    workflow([['approvers' => [user()]]], ['slug' => 'purchase-order']);

    $order->submitForApproval(null, null, user());

    expect($order->pendingDraft())->toBeNull()
        ->and($order->isPendingApproval())->toBeTrue();
});

afterEach(function (): void {
    PurchaseOrder::$actingSubmitter = null;
});
