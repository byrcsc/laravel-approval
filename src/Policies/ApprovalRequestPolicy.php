<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Policies;

use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use Illuminate\Database\Eloquent\Model;

/**
 * The engine's own rules, expressed where a Laravel application expects to find
 * them.
 *
 * This is a floor, not a ceiling. It answers only what the package can know -
 * who holds an assignment, whose turn it is, who submitted - and knows nothing about
 * your organization. A host that needs "and they must still be employed" or
 * "and the amount is under their limit" should extend this and call these rules
 * from theirs, rather than fork them.
 *
 * Nothing registers it: `Models\ApprovalRequest` and `Policies\*Policy` are the
 * pair Laravel's own policy guesser looks for, so `$user->can('approve', ...)`
 * works with no wiring at all. There is deliberately no config option for it,
 * because an option that could not switch the convention off would be a promise
 * this package cannot keep. To replace it, register yours the ordinary way -
 * `Gate::policy(ApprovalRequest::class, YourPolicy::class)` - and yours wins.
 *
 * Note `returnToSubmitter`: `return` is a reserved word, so the ability is
 * named for what it does rather than for the method that does it.
 */
class ApprovalRequestPolicy
{
    /**
     * Everyone with standing in the request. Wider than the ability to act on
     * it, because reading a decision is not making one.
     */
    public function view(Model $user, ApprovalRequest $request): bool
    {
        return $request->involves($user);
    }

    public function approve(Model $user, ApprovalRequest $request): bool
    {
        return Approval::canApprove($request, $user);
    }

    /**
     * Rejecting and returning pass through the same gate as approving: all
     * three are that assignment's decision, and differ only in which way it went.
     */
    public function reject(Model $user, ApprovalRequest $request): bool
    {
        return Approval::canApprove($request, $user);
    }

    public function returnToSubmitter(Model $user, ApprovalRequest $request): bool
    {
        return Approval::canApprove($request, $user);
    }

    public function withdraw(Model $user, ApprovalRequest $request): bool
    {
        return Approval::canWithdraw($request, $user);
    }

    /**
     * Administrative cancellation is host-owned. Applications grant this in
     * their own policy rather than inheriting requester authority.
     */
    public function cancel(Model $user, ApprovalRequest $request): bool
    {
        return false;
    }

    public function delegate(Model $user, ApprovalRequest $request): bool
    {
        return Approval::canDelegate($request, $user);
    }
}
