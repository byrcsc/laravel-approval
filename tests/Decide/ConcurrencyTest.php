<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Enums\RejectionPolicy;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Enums\StageStatus;
use ByRcsc\LaravelApproval\Exceptions\AlreadyDecidedException;
use ByRcsc\LaravelApproval\Exceptions\UnauthorizedApproverException;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;

/**
 * Two callers rarely share a model instance: each PHP process loads its own
 * copy, acts on what it read, and finds out afterwards whether the world moved.
 * Every action defends against that by taking the row lock and reloading before
 * it checks anything - these tests hold a copy loaded *before* a competing
 * action and prove the reload wins over the stale memory.
 *
 * What SQLite cannot exercise is the lock itself: its grammar compiles
 * `lockForUpdate()` to nothing, and a single in-memory connection cannot
 * interleave two open transactions anyway. On MySQL and Postgres the same
 * reload runs behind a real row lock, which closes the read-check-write window
 * these tests approximate sequentially.
 */
function staleCopyOf(ApprovalRequest $request): ApprovalRequest
{
    return ApprovalRequest::query()->findOrFail($request->getKey());
}

it('refuses a decision arriving through a copy loaded before the rejection', function (): void {
    $approver = user('Approver');
    $rejector = user('Rejector');

    $workflow = workflow([[
        'required_approvals' => 1,
        'rejection_policy' => RejectionPolicy::AnyRejectionVetoes,
        'approvers' => [$approver, $rejector],
    ]]);
    $request = post()->submitForApproval($workflow, null, user());

    $stale = staleCopyOf($request);

    Approval::reject($request, $rejector, 'Not like this.');

    expect(fn () => Approval::approve($stale, $approver))
        ->toThrow(AlreadyDecidedException::class)
        ->and($stale->state)->toBe(RequestState::Rejected);
});

it('refuses a decision arriving through a copy loaded before the withdrawal', function (): void {
    $approver = user('Approver');
    $submitter = user('Submitter');

    $workflow = workflow([['approvers' => [$approver]]]);
    $request = post()->submitForApproval($workflow, null, $submitter);

    $stale = staleCopyOf($request);

    Approval::withdraw($request, $submitter);

    expect(fn () => Approval::approve($stale, $approver))
        ->toThrow(AlreadyDecidedException::class);
});

it('refuses a withdrawal arriving through a copy loaded before the final decision', function (): void {
    $approver = user('Approver');
    $submitter = user('Submitter');

    $workflow = workflow([['approvers' => [$approver]]]);
    $request = post()->submitForApproval($workflow, null, $submitter);

    $stale = staleCopyOf($request);

    Approval::approve($request, $approver);

    expect(fn () => Approval::withdraw($stale, $submitter))
        ->toThrow(AlreadyDecidedException::class)
        ->and($stale->state)->toBe(RequestState::Approved);
});

it('refuses a duplicate decision arriving through a second copy', function (): void {
    $approver = user('Approver');

    $workflow = workflow([['required_approvals' => 2, 'approvers' => [$approver, user('Other')]]]);
    $request = post()->submitForApproval($workflow, null, user());

    $stale = staleCopyOf($request);

    Approval::approve($request, $approver);

    expect(fn () => Approval::approve($stale, $approver))
        ->toThrow(AlreadyDecidedException::class)
        ->and($request->refresh()->state)->toBe(RequestState::Pending);
});

it('applies the draft exactly once when the losing racer arrives after required approvals', function (): void {
    $one = user('One');
    $two = user('Two');
    $post = post('Original title');

    $workflow = workflow([['required_approvals' => 1, 'approvers' => [$one, $two]]]);
    $request = $post->submitForApproval($workflow, ['title' => 'Revised title'], user());

    // Both load the request while the required approvals still stands at zero.
    $copyForOne = staleCopyOf($request);
    $copyForTwo = staleCopyOf($request);

    Approval::approve($copyForOne, $one);

    expect(fn () => Approval::approve($copyForTwo, $two))
        ->toThrow(AlreadyDecidedException::class)
        ->and($post->fresh()?->getAttributeValue('title'))->toBe('Revised title')
        ->and($request->actions()->where('action', 'applied')->count())->toBe(1)
        ->and($request->actions()->where('action', 'approved')->count())->toBe(1);
});

it('counts both racers when the required approvals has room for both', function (): void {
    $one = user('One');
    $two = user('Two');
    $post = post('Original title');

    $workflow = workflow([['required_approvals' => 2, 'approvers' => [$one, $two, user('Three')]]]);
    $request = $post->submitForApproval($workflow, ['title' => 'Revised title'], user());

    $copyForOne = staleCopyOf($request);
    $copyForTwo = staleCopyOf($request);

    Approval::approve($copyForOne, $one);
    Approval::approve($copyForTwo, $two);

    // The second racer's reload sees the first decision, completes the required approvals, and
    // carries the stage exactly once.
    expect($request->refresh()->state)->toBe(RequestState::Approved)
        ->and($post->fresh()?->getAttributeValue('title'))->toBe('Revised title')
        ->and($request->actions()->where('action', 'applied')->count())->toBe(1)
        ->and($request->stages()->sole()->state)->toBe(StageStatus::Approved);
});

it('refuses the second of two racing rejections', function (): void {
    $one = user('One');
    $two = user('Two');

    $workflow = workflow([['required_approvals' => 2, 'approvers' => [$one, $two]]]);
    $request = post()->submitForApproval($workflow, null, user());

    $copyForOne = staleCopyOf($request);
    $copyForTwo = staleCopyOf($request);

    Approval::reject($copyForOne, $one, 'Wrong quarter.');

    expect(fn () => Approval::reject($copyForTwo, $two, 'Also no.'))
        ->toThrow(AlreadyDecidedException::class)
        ->and($request->refresh()->state)->toBe(RequestState::Rejected)
        ->and($request->actions()->where('action', 'rejected')->count())->toBe(1);
});

it('refuses a stale decision against a stage that has already advanced', function (): void {
    $first = user('First');
    $second = user('Second');

    $workflow = workflow([
        ['approvers' => [$first]],
        ['approvers' => [$second]],
    ]);

    $request = post()->submitForApproval($workflow, null, user());

    $stale = staleCopyOf($request);

    Approval::approve($request, $first);

    // First's copy still shows stage one waiting; the reload knows the request
    // has moved to a stage First holds no assignment on, which is the accurate
    // refusal - "already decided" belongs to the request, not to them.
    expect(fn () => Approval::approve($stale, $first))
        ->toThrow(UnauthorizedApproverException::class)
        ->and($request->refresh()->getAttributeValue('current_stage_sequence'))->toBe(2);
});
