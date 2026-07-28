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
 * The step and ballot come along because a caller that has asked is usually
 * about to render something - "2 of 3 approvals, waiting on Finance" needs the
 * same objects the check already loaded, and making them ask twice would make
 * the cheap question expensive.
 */
final readonly class Eligibility
{
    private function __construct(
        public bool $eligible,
        public ?Ineligibility $reason,
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

    /**
     * The step and ballot are passed where the denial happened late enough for
     * them to exist - a delegated seat knows which level it is on, a closed
     * request does not.
     */
    public static function denied(
        Ineligibility $reason,
        ?ApprovalRequestStep $step = null,
        ?Ballot $ballot = null,
    ): self {
        return new self(false, $reason, $step, $ballot, null);
    }

    /**
     * What the engine needs to record a vote, or null when this actor may not
     * cast one. The narrowing is the point: only a granted eligibility can
     * produce a context, so nothing downstream has to re-check.
     */
    public function context(): ?DecisionContext
    {
        if (! $this->eligible || $this->step === null || $this->ballot === null || $this->seat === null) {
            return null;
        }

        return new DecisionContext($this->step, $this->ballot, $this->seat);
    }
}
