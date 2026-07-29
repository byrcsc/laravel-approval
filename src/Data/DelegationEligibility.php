<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Data;

use ByRcsc\LaravelApproval\Enums\DelegationIneligibility;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStep;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStepApprover;

final readonly class DelegationEligibility
{
    private function __construct(
        public bool $eligible,
        public ?DelegationIneligibility $reason,
        public ?ApprovalRequestStep $stage,
        public ?StageProgress $stageProgress,
        public ?ApprovalRequestStepApprover $assignment,
    ) {}

    public static function granted(
        ApprovalRequestStep $stage,
        StageProgress $stageProgress,
        ApprovalRequestStepApprover $assignment,
    ): self {
        return new self(true, null, $stage, $stageProgress, $assignment);
    }

    public static function denied(
        DelegationIneligibility $reason,
        ?ApprovalRequestStep $stage = null,
        ?StageProgress $stageProgress = null,
    ): self {
        return new self(false, $reason, $stage, $stageProgress, null);
    }
}
