<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Data\RevisedDraft;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Tests\Stubs\Post;
use Illuminate\Support\Facades\Gate;

// Current-state scopes inspect the latest request; historical scopes search
// the whole request history. A mixed history is where the two disagree.

it('distinguishes latest-state scopes from historical scopes across a mixed history', function (): void {
    $approver = user('Approver');
    $flow = workflow([['approvers' => [$approver]]]);

    // Rejected once, then revised and approved: currently approved.
    $recovered = post('Recovered');
    $first = $recovered->submitForApproval($flow, null, user('Author'));
    Approval::reject($first, $approver, 'Not yet.');
    $second = Approval::reviseAndResubmit($first, RevisedDraft::inherit());
    Approval::approve($second, $approver);

    // Rejected and never retried: currently rejected.
    $refused = post('Refused');
    Approval::reject($refused->submitForApproval($flow, null, user('Author')), $approver, 'No.');

    // Never rejected, still pending.
    $open = post('Open');
    $open->submitForApproval($flow, null, user('Author'));

    expect(Post::query()->approvalApproved()->pluck('title')->all())->toBe(['Recovered'])
        ->and(Post::query()->approvalRejected()->pluck('title')->all())->toBe(['Refused'])
        ->and(Post::query()->pendingApproval()->pluck('title')->all())->toBe(['Open'])
        ->and(Post::query()->everInApprovalState(RequestState::Rejected)->orderBy('id')->pluck('title')->all())
        ->toBe(['Recovered', 'Refused'])
        ->and(Post::query()->approvalState(RequestState::Approved, RequestState::Pending)->orderBy('id')->pluck('title')->all())
        ->toBe(['Recovered', 'Open']);
});

it('scopes returned, withdrawn, cancelled, and superseded models by their latest request', function (): void {
    $approver = user('Approver');
    $admin = user('Admin');
    $flow = workflow([['approvers' => [$approver]]]);

    $returned = post('Returned');
    Approval::returnToSubmitter($returned->submitForApproval($flow, null, user('Author')), $approver, 'Fix it.');

    $withdrawn = post('Withdrawn');
    $author = user('Withdrawing author');
    Approval::withdraw($withdrawn->submitForApproval($flow, null, $author), $author);

    Gate::before(fn ($actor, string $ability): ?bool => $ability === 'cancel' ? true : null);
    $cancelled = post('Cancelled');
    Approval::cancel($cancelled->submitForApproval($flow, null, user('Author')), $admin);

    // A conflicted request that is revised becomes superseded by its
    // replacement; the replacement is then the latest, pending request.
    $conflicted = post('Conflicted');
    $request = $conflicted->submitForApproval($flow, ['title' => 'Edited'], user('Author'));
    $conflicted->newQuery()->whereKey($conflicted->getKey())->update(['title' => 'Moved underneath']);
    Approval::approve($request, $approver);

    expect(Post::query()->approvalReturned()->pluck('title')->all())->toBe(['Returned'])
        ->and(Post::query()->approvalWithdrawn()->pluck('title')->all())->toBe(['Withdrawn'])
        ->and(Post::query()->approvalCancelled()->pluck('title')->all())->toBe(['Cancelled'])
        ->and(Post::query()->approvalConflicted()->pluck('title')->all())->toBe(['Moved underneath']);

    Approval::reviseAndResubmit($request->refresh(), RevisedDraft::withAttributes(['title' => 'Second try']));

    expect(Post::query()->approvalSuperseded()->count())->toBe(0)
        ->and(Post::query()->everInApprovalState(RequestState::Superseded)->pluck('title')->all())
        ->toBe(['Moved underneath'])
        ->and(Post::query()->pendingApproval()->orderBy('id')->pluck('title')->all())
        ->toContain('Moved underneath');
});
