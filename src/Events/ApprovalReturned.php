<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Events;

use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStage;
use Illuminate\Database\Eloquent\Model;

final class ApprovalReturned extends ApprovalEvent
{
    public function __construct(
        ApprovalRequest $request,
        public readonly ApprovalRequestStage $stage,
        public readonly Model $actor,
        public readonly string $comment,
    ) {
        parent::__construct($request);
    }
}
