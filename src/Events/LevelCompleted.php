<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Events;

use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStep;

/**
 * A level reached its quorum. `$next` is the level now waiting, or null when
 * this was the last one - in which case {@see ApprovalCompleted} follows.
 */
final class LevelCompleted extends ApprovalEvent
{
    public function __construct(
        ApprovalRequest $request,
        public readonly ApprovalRequestStep $step,
        public readonly ?ApprovalRequestStep $next = null,
    ) {
        parent::__construct($request);
    }
}
