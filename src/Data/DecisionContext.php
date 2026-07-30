<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Data;

use ByRcsc\LaravelApproval\Models\ApprovalRequestAssignment;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStage;

/**
 * The stage and assignment established before a decision is recorded.
 */
final readonly class DecisionContext
{
    public function __construct(
        public ApprovalRequestStage $stage,
        public StageProgress $stageProgress,
        public ApprovalRequestAssignment $assignment,
    ) {}
}
