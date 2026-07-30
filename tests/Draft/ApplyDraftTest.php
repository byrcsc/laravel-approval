<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Events\ApprovalCompleted;
use ByRcsc\LaravelApproval\Events\DraftApplied;
use ByRcsc\LaravelApproval\Events\DraftConflicted;
use ByRcsc\LaravelApproval\Facades\Approval;
use Illuminate\Support\Facades\Event;

it('writes the draft onto the record on final approval', function (): void {
    $approver = user('Approver');
    $post = post('Original title');
    $workflow = workflow([['approvers' => [$approver]]]);

    $request = $post->submitForApproval($workflow, ['title' => 'Revised title', 'published' => true], user());
    Approval::approve($request, $approver);

    $fresh = $post->fresh();

    expect($request->refresh()->state)->toBe(RequestState::Approved)
        ->and($fresh?->getAttributeValue('title'))->toBe('Revised title')
        ->and($fresh?->getAttributeValue('published'))->toBeTrue();
});

it('waits for the last level before writing anything', function (): void {
    $first = user('First');
    $second = user('Second');
    $post = post('Original title');

    $workflow = workflow([
        ['approvers' => [$first]],
        ['approvers' => [$second]],
    ]);

    $request = $post->submitForApproval($workflow, ['title' => 'Revised'], user());

    Approval::approve($request, $first);
    expect($post->fresh()?->getAttributeValue('title'))->toBe('Original title');

    Approval::approve($request, $second);
    expect($post->fresh()?->getAttributeValue('title'))->toBe('Revised');
});

it('approves a record-gating request without touching anything', function (): void {
    $approver = user();
    $post = post('Unchanged');
    $workflow = workflow([['approvers' => [$approver]]]);

    $request = $post->submitForApproval($workflow, null, user());
    Approval::approve($request, $approver);

    expect($request->refresh()->state)->toBe(RequestState::Approved)
        ->and($post->fresh()?->getAttributeValue('title'))->toBe('Unchanged')
        ->and($request->actions()->where('action', 'applied')->count())->toBe(0);
});

it('writes past the model guard', function (): void {
    $approver = user();
    $order = purchaseOrder('PO-1', 500);
    workflow([['approvers' => [$approver]]], ['slug' => 'purchase-order']);

    $request = $order->submitForApproval(null, ['amount' => 900], user());
    Approval::approve($request, $approver);

    expect($order->fresh()?->getAttributeValue('amount'))->toBe(900);
});

it('records what it applied', function (): void {
    $approver = user();
    $post = post('Original title');
    $workflow = workflow([['approvers' => [$approver]]]);

    $request = $post->submitForApproval($workflow, ['title' => 'Revised title'], user());
    Approval::approve($request, $approver);

    $action = $request->actions()->where('action', 'applied')->sole();

    expect(canonical($action->getAttributeValue('metadata')))->toBe(canonical([
        'changes' => ['title' => ['from' => 'Original title', 'to' => 'Revised title']],
    ]));
});

it('announces the applied draft after the record is saved', function (): void {
    Event::fake([DraftApplied::class, ApprovalCompleted::class]);

    $approver = user();
    $post = post('Original title');
    $workflow = workflow([['approvers' => [$approver]]]);

    $request = $post->submitForApproval($workflow, ['title' => 'Revised title'], user());
    Approval::approve($request, $approver);

    Event::assertDispatched(ApprovalCompleted::class);
    Event::assertDispatched(DraftApplied::class, function (DraftApplied $e): bool {
        return $e->approvable->getAttributeValue('title') === 'Revised title'
            && $e->draft->new === ['title' => 'Revised title'];
    });
});

describe('the stale check', function (): void {
    it('conflicts rather than overwriting an edit made while it waited', function (): void {
        Event::fake([DraftConflicted::class, DraftApplied::class]);

        $approver = user();
        $post = post('Original title');
        $workflow = workflow([['approvers' => [$approver]]]);

        $request = $post->submitForApproval($workflow, ['title' => 'Revised title'], user());

        // Somebody edits the record while the approval is in flight.
        $post->forceFill(['title' => 'Edited by somebody else'])->save();

        Approval::approve($request, $approver);

        expect($request->refresh()->state)->toBe(RequestState::Conflicted)
            ->and($request->completed_at)->toBeNull()
            ->and($post->fresh()?->getAttributeValue('title'))->toBe('Edited by somebody else');

        Event::assertNotDispatched(DraftApplied::class);
        Event::assertDispatched(DraftConflicted::class, fn (DraftConflicted $e): bool => $e->attributes === ['title']);
    });

    it('ignores changes to attributes the draft never claimed', function (): void {
        $approver = user();
        $post = post('Original title');
        $workflow = workflow([['approvers' => [$approver]]]);

        $request = $post->submitForApproval($workflow, ['title' => 'Revised title'], user());

        // A different column moved; the draft has no opinion about it.
        $post->forceFill(['body' => 'Rewritten body'])->save();

        Approval::approve($request, $approver);

        expect($request->refresh()->state)->toBe(RequestState::Approved)
            ->and($post->fresh()?->getAttributeValue('title'))->toBe('Revised title')
            ->and($post->fresh()?->getAttributeValue('body'))->toBe('Rewritten body');
    });

    it('names every attribute that drifted', function (): void {
        $approver = user();
        $post = post('Original title');
        $workflow = workflow([['approvers' => [$approver]]]);

        $request = $post->submitForApproval($workflow, ['title' => 'A', 'body' => 'B'], user());
        $post->forceFill(['title' => 'Moved', 'body' => 'Also moved'])->save();

        Approval::approve($request, $approver);

        $metadata = $request->refresh()
            ->actions()
            ->where('action', 'conflicted')
            ->sole()
            ->getAttributeValue('metadata');

        expect(is_array($metadata) ? ($metadata['reason'] ?? null) : null)
            ->toBe('stale_attributes')
            ->and(collect(is_array($metadata) ? ($metadata['attributes'] ?? []) : [])->sort()->values()->all())
            ->toBe(['body', 'title']);
    });

    it('accepts a record edited back to where it started', function (): void {
        $approver = user();
        $post = post('Original title');
        $workflow = workflow([['approvers' => [$approver]]]);

        $request = $post->submitForApproval($workflow, ['title' => 'Revised'], user());

        $post->forceFill(['title' => 'Wandered off'])->save();
        $post->forceFill(['title' => 'Original title'])->save();

        Approval::approve($request, $approver);

        expect($request->refresh()->state)->toBe(RequestState::Approved);
    });

    it('cancels the request when the record is gone entirely', function (): void {
        $post = post('Original title');
        $workflow = workflow([['approvers' => [user()]]]);

        $request = $post->submitForApproval($workflow, ['title' => 'Revised'], user());
        $post->delete();

        expect($request->refresh()->state)->toBe(RequestState::Cancelled)
            ->and($request->actions()->where('action', 'system_closed')->sole()->actor_id)->toBeNull();
    });
});
