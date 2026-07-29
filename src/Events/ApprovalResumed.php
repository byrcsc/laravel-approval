<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Events;

use ByRcsc\LaravelApproval\Models\ApprovalRequest;

final class ApprovalResumed extends ApprovalEvent
{
    public function __construct(
        ApprovalRequest $request,
        public readonly ApprovalRequest $returnedRequest,
    ) {
        parent::__construct($request);
    }
}
