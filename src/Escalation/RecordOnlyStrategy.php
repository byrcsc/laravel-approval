<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Escalation;

use ByRcsc\LaravelApproval\Contracts\EscalationStrategy;
use ByRcsc\LaravelApproval\Enums\EscalationAction;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStage;

/**
 * Record the overdue state and emit the event; decide nothing.
 *
 * The default, and empty on purpose. Marking the stage, firing `StageOverdue`,
 * and writing the audit line all happen before any strategy runs, so by the
 * time this is called everything record-only means has already happened. It
 * promises no delivery: turning on notifications - or listening for the
 * event - is what turns the recorded fact into a message.
 *
 * Doing nothing further is also the right default for a deadline. An approval
 * that is late is late; deciding it automatically is a policy an organization
 * has to choose, not one a package should assume.
 */
final class RecordOnlyStrategy implements EscalationStrategy
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function handle(
        ApprovalRequest $request,
        ApprovalRequestStage $stage,
        EscalationAction $action,
        array $config,
    ): void {
        // Deliberately empty. See the class docblock.
    }
}
