<?php

declare(strict_types=1);

namespace Workbench\App\Policies;

use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Policies\ApprovalRequestPolicy as PackagePolicy;
use Illuminate\Database\Eloquent\Model;
use Workbench\App\Models\User;

/**
 * The demo's organizational rules on top of the package's.
 *
 * The package's policy is the one class it means to be subclassed. Its
 * abilities answer only what the engine knows - who holds an assignment, which
 * stage is active, who requested it - and anything about roles or seniority has
 * to come from the application. Extending rather than replacing keeps the
 * engine's checks in force: `approve()` here is inherited, so it still runs the
 * same eligibility calculation a decision runs.
 *
 * Two abilities are widened:
 *
 * - `cancel` is denied outright by the package, because inheriting requester
 *   authority for an administrative act would be a surprise. The demo grants it
 *   to the executive team.
 * - `view` is the package's "anyone with standing in the request". The demo
 *   widens it to auditors, who have standing in every request and none of the
 *   assignments that would prove it.
 *
 * Registered in `WorkbenchServiceProvider` with `Gate::policy()`, which takes
 * precedence over Laravel's policy discovery.
 */
final class ApprovalRequestPolicy extends PackagePolicy
{
    public function view(Model $user, ApprovalRequest $request): bool
    {
        return parent::view($user, $request)
            || $this->isOn($user, 'audit');
    }

    public function cancel(Model $user, ApprovalRequest $request): bool
    {
        return $this->isOn($user, 'executive');
    }

    private function isOn(Model $user, string $team): bool
    {
        return $user instanceof User && $user->isOn($team);
    }
}
