<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Events;

use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStep;
use Illuminate\Database\Eloquent\Model;

/**
 * One vote landed. The level may or may not be carried by it - listen for
 * {@see LevelCompleted} if that is the question.
 */
final class StepApproved extends ApprovalEvent
{
    public function __construct(
        ApprovalRequest $request,
        public readonly ApprovalRequestStep $step,
        public readonly Model $actor,
        public readonly int $votes,
        public readonly int $quorum,
    ) {
        parent::__construct($request);
    }
}
