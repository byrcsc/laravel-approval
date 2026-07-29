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
        public ?ApprovalRequestStep $step,
        public ?Ballot $ballot,
        public ?ApprovalRequestStepApprover $seat,
    ) {}

    public static function granted(
        ApprovalRequestStep $step,
        Ballot $ballot,
        ApprovalRequestStepApprover $seat,
    ): self {
        return new self(true, null, $step, $ballot, $seat);
    }

    public static function denied(
        DelegationIneligibility $reason,
        ?ApprovalRequestStep $step = null,
        ?Ballot $ballot = null,
    ): self {
        return new self(false, $reason, $step, $ballot, null);
    }
}
