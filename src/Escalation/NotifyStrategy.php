<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Escalation;

use ByRcsc\LaravelApproval\Contracts\EscalationStrategy;
use ByRcsc\LaravelApproval\Enums\EscalationAction;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStep;

/**
 * Tell somebody; change nothing.
 *
 * The default, and empty on purpose. Marking the step, firing `StepOverdue`,
 * and writing the audit line all happen before any strategy runs, so by the
 * time this is called everything "notify" means has already happened. Listening
 * for the event - or turning on the shipped overdue notification - is what
 * turns it into a message.
 *
 * Doing nothing is also the right default for a deadline. An approval that is
 * late is late; deciding it automatically is a policy an organization has to
 * choose, not one a package should assume.
 */
final class NotifyStrategy implements EscalationStrategy
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function handle(
        ApprovalRequest $request,
        ApprovalRequestStep $step,
        EscalationAction $action,
        array $config,
    ): void {
        // Deliberately empty. See the class docblock.
    }
}
