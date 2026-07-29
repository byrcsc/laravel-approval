<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Data;

use ByRcsc\LaravelApproval\Models\ApprovalRequestStep;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStepApprover;

/**
 * The stage and assignment established before a decision is recorded.
 */
final readonly class DecisionContext
{
    public function __construct(
        public ApprovalRequestStep $stage,
        public StageProgress $stageProgress,
        public ApprovalRequestStepApprover $assignment,
    ) {}
}
