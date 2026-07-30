<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Events;

use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStage;

/**
 * A stage's approval rule was satisfied. `$next` is the stage now waiting, or null when
 * this was the last one - in which case {@see ApprovalCompleted} follows.
 */
final class StageCompleted extends ApprovalEvent
{
    public function __construct(
        ApprovalRequest $request,
        public readonly ApprovalRequestStage $stage,
        public readonly ?ApprovalRequestStage $next = null,
    ) {
        parent::__construct($request);
    }
}
