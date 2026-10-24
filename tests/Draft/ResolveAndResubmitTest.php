<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Data\RevisedDraft;
use ByRcsc\LaravelApproval\Enums\ConflictResolution;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Enums\StageStatus;
use ByRcsc\LaravelApproval\Events\DraftApplied;
use ByRcsc\LaravelApproval\Exceptions\AlreadyDecidedException;
use ByRcsc\LaravelApproval\Exceptions\DraftConflictException;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Tests\Stubs\Post;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

/**
 * @return array{0: ApprovalRequest, 1: Post}
 */
function conflictedRequest(): array
{
    $approver = user('Approver');
    $post = post('Original title');
    $workflow = workflow([['approvers' => [$approver]]]);

    $request = $post->submitForApproval($workflow, ['title' => 'Revised title'], user('Author'));
    $post->forceFill(['title' => 'Edited by somebody else'])->save();
    Approval::approve($request, $approver);

    return [$request->refresh(), $post];
}

describe('resolving a conflict', function (): void {
    it('reapplies the draft over what the record now holds', function (): void {
        Event::fake([DraftApplied::class]);

        [$request, $post] = conflictedRequest();
        $admin = user('Admin');

        Approval::resolve($request, ConflictResolution::Reapply, $admin);

        expect($request->refresh()->state)->toBe(RequestState::Approved)
            ->and($post->fresh()?->getAttributeValue('title'))->toBe('Revised title');

        Event::assertDispatched(DraftApplied::class);
    });

    it('keeps a record of what it overwrote', function (): void {
        [$request] = conflictedRequest();
        $admin = user('Admin');

        Approval::resolve($request, ConflictResolution::Reapply, $admin);

        $applied = $request->refresh()->actions()->where('action', 'applied')->sole();

        expect($applied->getAttributeValue('metadata')['forced'] ?? null)->toBe(['title'])
            ->and($applied->actor?->getKey())->toBe($admin->getKey());
    });

    it('discards the draft and leaves the record alone', function (): void {
        [$request, $post] = conflictedRequest();
        Gate::before(fn ($actor, string $ability): ?bool => $ability === 'cancel' ? true : null);

        Approval::resolve($request, ConflictResolution::Discard, user('Admin'), 'Their edit wins.');

        expect($request->refresh()->state)->toBe(RequestState::Cancelled)
            ->and($post->fresh()?->getAttributeValue('title'))->toBe('Edited by somebody else')
            ->and($request->actions()->where('action', 'cancelled')->sole()->getAttributeValue('comment'))
            ->toBe('Their edit wins.');
    });

    it('frees the record when the draft is discarded', function (): void {
        [$request, $post] = conflictedRequest();
        Gate::before(fn ($actor, string $ability): ?bool => $ability === 'cancel' ? true : null);

        expect($post->isPendingApproval())->toBeTrue();

        Approval::resolve($request, ConflictResolution::Discard, user('Admin'));

        expect($post->isPendingApproval())->toBeFalse();
    });

    it('refuses to resolve a request that is not conflicted', function (): void {
        $approver = user();
        $workflow = workflow([['approvers' => [$approver]]]);
        $request = post()->submitForApproval($workflow, null, user());

        expect(fn () => Approval::resolve($request, ConflictResolution::Reapply))
            ->toThrow(DraftConflictException::class, 'is pending, not conflicted');
    });

    it('refuses to reapply a draft whose record has gone', function (): void {
        [$request, $post] = conflictedRequest();

        // Deleted without model events, so the listener that closes requests
        // for a deleted record never runs and the request stays conflicted.
        Post::query()->whereKey($post->getKey())->delete();

        expect(fn () => Approval::resolve($request, ConflictResolution::Reapply, user('Admin')))
            ->toThrow(DraftConflictException::class, 'no longer exists')
            ->and($request->refresh()->state)->toBe(RequestState::Conflicted);
    });
});

describe('resubmitting', function (): void {
    it('opens a fresh request chained to the one it replaces', function (): void {
        $approver = user('Approver');
        $author = user('Author');
        $post = post();
        $workflow = workflow([['approvers' => [$approver]]]);

        $first = $post->submitForApproval($workflow, ['title' => 'Take one'], $author);
        Approval::reject($first, $approver, 'Not quite.');

        $second = Approval::reviseAndResubmit($first, RevisedDraft::inherit());

        expect($second->is($first))->toBeFalse()
            ->and($second->state)->toBe(RequestState::Pending)
            ->and($second->getAttributeValue('resubmitted_from_id'))->toBe($first->getKey())
            ->and($second->resubmittedFrom?->is($first))->toBeTrue()
            ->and($first->refresh()->resubmissions()->count())->toBe(1);
    });

    it('carries the original draft and requester forward', function (): void {
        $approver = user('Approver');
        $author = user('Author');
        $post = post();
        $workflow = workflow([['approvers' => [$approver]]]);

        $first = $post->submitForApproval($workflow, ['title' => 'Take one'], $author);
        Approval::reject($first, $approver, 'No.');

        $second = Approval::reviseAndResubmit($first, RevisedDraft::inherit());

        expect($second->getAttributeValue('new_attributes'))->toBe(['title' => 'Take one'])
            ->and($second->requester?->getKey())->toBe($author->getKey());
    });

    it('takes a revised draft instead when one is given', function (): void {
        $approver = user('Approver');
        $post = post();
        $workflow = workflow([['approvers' => [$approver]]]);

        $first = $post->submitForApproval($workflow, ['title' => 'Take one'], user('Author'));
        Approval::reject($first, $approver, 'Try a better title.');

        $second = Approval::reviseAndResubmit($first, RevisedDraft::withAttributes(['title' => 'Take two']));

        expect($second->getAttributeValue('new_attributes'))->toBe(['title' => 'Take two']);
    });

    it('starts again at stage one', function (): void {
        $first = user('First');
        $second = user('Second');
        $post = post();

        $workflow = workflow([
            ['approvers' => [$first]],
            ['approvers' => [$second]],
        ]);

        $request = $post->submitForApproval($workflow, null, user('Author'));
        Approval::approve($request, $first);
        Approval::reject($request, $second, 'Finance says no.');

        $retry = Approval::reviseAndResubmit($request, RevisedDraft::inherit());

        expect($retry->getAttributeValue('current_stage_sequence'))->toBe(1)
            ->and($retry->stages()->get()->pluck('state')->all())->toBe([StageStatus::Active, StageStatus::Queued]);
    });

    it('takes a fresh snapshot, picking up workflow edits', function (): void {
        $approver = user('Approver');
        $extra = user('Extra');
        $post = post();

        $workflow = workflow([['required_approvals' => 1, 'approvers' => [$approver]]]);

        $first = $post->submitForApproval($workflow, null, user('Author'));
        Approval::reject($first, $approver, 'No.');

        // The workflow is tightened in response to the rejection.
        $stage = $workflow->stages()->sole();
        approverRow($stage, $extra);
        $stage->forceFill(['required_approvals' => 2])->save();

        $second = Approval::reviseAndResubmit($first, RevisedDraft::inherit());

        expect($second->stages()->sole()->getAttributeValue('required_approvals'))->toBe(2)
            ->and($second->stages()->sole()->assignments()->count())->toBe(2)
            // The first request kept the rules it was judged by.
            ->and($first->refresh()->stages()->sole()->getAttributeValue('required_approvals'))->toBe(1);
    });

    it('notes in the audit trail what it was retrying', function (): void {
        $approver = user('Approver');
        $post = post();
        $workflow = workflow([['approvers' => [$approver]]]);

        $first = $post->submitForApproval($workflow, null, user('Author'));
        Approval::reject($first, $approver, 'No.');

        $second = Approval::reviseAndResubmit($first, RevisedDraft::inherit());

        expect($second->actions()->where('action', 'resubmitted')->sole()->getAttributeValue('metadata'))
            ->toBe(['resubmitted_from' => $first->getAttributeValue('ulid')]);
    });

    it('retries a withdrawn request', function (): void {
        $author = user('Author');
        $post = post();
        $workflow = workflow([['approvers' => [user('Approver')]]]);

        $first = $post->submitForApproval($workflow, null, $author);
        Approval::withdraw($first, $author);

        expect(Approval::reviseAndResubmit($first, RevisedDraft::inherit())->state)->toBe(RequestState::Pending);
    });

    it('retries a conflicted request against current values', function (): void {
        [$first, $post] = conflictedRequest();

        $second = Approval::reviseAndResubmit($first, RevisedDraft::withAttributes(['title' => 'Third attempt']));

        expect($second->state)->toBe(RequestState::Pending)
            ->and($second->getAttributeValue('original_attributes'))->toBe(['title' => 'Edited by somebody else']);

        expect($post->fresh()?->getAttributeValue('title'))->toBe('Edited by somebody else');
    });

    it('refuses to retry one that succeeded', function (): void {
        $approver = user();
        $workflow = workflow([['approvers' => [$approver]]]);
        $request = post()->submitForApproval($workflow, null, user());

        Approval::approve($request, $approver);

        expect(fn () => Approval::reviseAndResubmit($request, RevisedDraft::inherit()))
            ->toThrow(AlreadyDecidedException::class, 'is approved and cannot be revised and resubmitted');
    });

    it('refuses to retry one that is still open', function (): void {
        $workflow = workflow([['approvers' => [user()]]]);
        $request = post()->submitForApproval($workflow, null, user());

        expect(fn () => Approval::reviseAndResubmit($request, RevisedDraft::inherit()))
            ->toThrow(AlreadyDecidedException::class, 'is pending and cannot be revised and resubmitted');
    });

});
