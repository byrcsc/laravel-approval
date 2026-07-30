<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Events;

use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStage;
use Illuminate\Database\Eloquent\Model;

/**
 * One approval decision was recorded for a stage.
 */
final class StageApprovalRecorded extends ApprovalEvent
{
    public function __construct(
        ApprovalRequest $request,
        public readonly ApprovalRequestStage $stage,
        public readonly Model $actor,
        public readonly int $approvalsReceived,
        public readonly int $approvalsRequired,
    ) {
        parent::__construct($request);
    }
}
