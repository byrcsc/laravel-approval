<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Data;

use ByRcsc\LaravelApproval\Enums\Ineligibility;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStep;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStepApprover;

/**
 * Whether somebody may decide a request, and if not, why not.
 *
 * The answer the engine has always computed, now returned instead of thrown.
 * `AuthorizeDecision` turns a denial into the matching exception, so the two
 * paths cannot drift: there is one calculation, and the difference between
 * asking and acting is only what happens to the answer.
 *
 * The stage, its progress, and the actor's assignment are included so callers
 * can render the result without repeating the same queries.
 */
final readonly class Eligibility
{
    private function __construct(
        public bool $eligible,
        public ?Ineligibility $reason,
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

    /**
     * Stage context is included when the request reached that part of the
     * eligibility check.
     */
    public static function denied(
        Ineligibility $reason,
        ?ApprovalRequestStep $stage = null,
        ?StageProgress $stageProgress = null,
    ): self {
        return new self(false, $reason, $stage, $stageProgress, null);
    }

    /**
     * The validated context needed to record a decision.
     */
    public function context(): ?DecisionContext
    {
        if (
            ! $this->eligible
            || $this->stage === null
            || $this->stageProgress === null
            || $this->assignment === null
        ) {
            return null;
        }

        return new DecisionContext($this->stage, $this->stageProgress, $this->assignment);
    }
}
