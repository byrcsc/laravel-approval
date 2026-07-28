<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Data;

use ByRcsc\LaravelApproval\Models\ApprovalRequestStep;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStepApprover;

/**
 * What the engine established before letting somebody decide: which level is
 * open, where the vote stands, and which seat the actor is speaking from.
 */
final readonly class DecisionContext
{
    public function __construct(
        public ApprovalRequestStep $step,
        public Ballot $ballot,
        public ApprovalRequestStepApprover $seat,
    ) {}
}
