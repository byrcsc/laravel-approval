<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Events;

use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestAssignment;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStage;

/**
 * An overdue stage's escalation policy added approvers. The assignments exist
 * when this fires, so listeners and notifications can reach the people who
 * now hold actionable work.
 *
 * Escalation is automated: the system is the source, not a human actor.
 */
final class ApproversEscalated extends ApprovalEvent
{
    /**
     * @param  list<ApprovalRequestAssignment>  $assignments  the assignments the policy created
     */
    public function __construct(
        ApprovalRequest $request,
        public readonly ApprovalRequestStage $stage,
        public readonly array $assignments,
    ) {
        parent::__construct($request);
    }
}
