<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Events;

use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStage;

/**
 * A request exists and its first stage is somebody's turn. `$stage` is that
 * stage - the one to notify.
 */
final class ApprovalSubmitted extends ApprovalEvent
{
    public function __construct(ApprovalRequest $request, public readonly ?ApprovalRequestStage $stage)
    {
        parent::__construct($request);
    }
}
