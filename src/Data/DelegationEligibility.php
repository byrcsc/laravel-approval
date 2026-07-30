<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Data;

use ByRcsc\LaravelApproval\Enums\DelegationIneligibility;
use ByRcsc\LaravelApproval\Models\ApprovalRequestAssignment;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStage;

final readonly class DelegationEligibility
{
    private function __construct(
        public bool $eligible,
        public ?DelegationIneligibility $reason,
        public ?ApprovalRequestStage $stage,
        public ?StageProgress $stageProgress,
        public ?ApprovalRequestAssignment $assignment,
    ) {}

    public static function granted(
        ApprovalRequestStage $stage,
        StageProgress $stageProgress,
        ApprovalRequestAssignment $assignment,
    ): self {
        return new self(true, null, $stage, $stageProgress, $assignment);
    }

    public static function denied(
        DelegationIneligibility $reason,
        ?ApprovalRequestStage $stage = null,
        ?StageProgress $stageProgress = null,
    ): self {
        return new self(false, $reason, $stage, $stageProgress, null);
    }
}
