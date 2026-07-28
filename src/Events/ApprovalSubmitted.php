<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Events;

use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStep;

/**
 * A request exists and its first level is somebody's turn. `$step` is that
 * level - the one to notify.
 */
final class ApprovalSubmitted extends ApprovalEvent
{
    public function __construct(ApprovalRequest $request, public readonly ApprovalRequestStep $step)
    {
        parent::__construct($request);
    }
}
