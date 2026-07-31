<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Policies;

use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use Illuminate\Database\Eloquent\Model;

/**
 * The package's own authorization rules, in the place a Laravel application
 * expects to find them.
 *
 * These abilities answer only what the package knows: who holds an assignment,
 * which stage is active, and who requested the approval. Applications with
 * additional organizational rules should extend this class and call these
 * methods from their own, rather than replace the checks.
 *
 * No registration is required. `Models\ApprovalRequest` and `Policies\*Policy`
 * match Laravel's policy discovery convention, so `$user->can('approve', ...)`
 * works out of the box. To use a different policy, register it the ordinary
 * way with `Gate::policy(ApprovalRequest::class, YourPolicy::class)`, which
 * takes precedence over discovery.
 */
class ApprovalRequestPolicy
{
    /**
     * Anyone with standing in the request. Deliberately wider than the ability
     * to act on it: reading a decision is not making one.
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
     * Rejecting and returning for revision pass through the same gate as
     * approving: all three are the same assignment's decision.
     */
    public function reject(Model $user, ApprovalRequest $request): bool
    {
        return Approval::canApprove($request, $user);
    }

    public function returnForRevision(Model $user, ApprovalRequest $request): bool
    {
        return Approval::canApprove($request, $user);
    }

    public function withdraw(Model $user, ApprovalRequest $request): bool
    {
        return Approval::canWithdraw($request, $user);
    }

    /**
     * Administrative cancellation is host-owned and denied by default.
     * Applications grant it in their own policy rather than inheriting
     * requester authority.
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
