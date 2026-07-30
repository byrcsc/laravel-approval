<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Events;

use ByRcsc\LaravelApproval\Enums\EscalationAction;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStage;

/**
 * A stage that already went overdue is still undecided at its configured
 * reminder cadence.
 *
 * Distinct from {@see StageOverdue}, which fires exactly once when the deadline
 * first passes. Reminders never rerun the escalation strategy; they exist so
 * listeners and notifications can chase a stage without re-escalating it.
 */
final class StageOverdueReminder extends ApprovalEvent
{
    public function __construct(
        ApprovalRequest $request,
        public readonly ApprovalRequestStage $stage,
        public readonly EscalationAction $action,
    ) {
        parent::__construct($request);
    }
}
