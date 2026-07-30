<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Events;

use ByRcsc\LaravelApproval\Models\ApprovalRequest;

final class ApprovalSystemClosed extends ApprovalEvent
{
    public function __construct(
        ApprovalRequest $request,
        public readonly ?string $reason = null,
    ) {
        parent::__construct($request);
    }
}
