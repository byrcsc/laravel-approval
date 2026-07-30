<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Events;

use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use Illuminate\Database\Eloquent\Model;

final class ApprovalWithdrawn extends ApprovalEvent
{
    public function __construct(
        ApprovalRequest $request,
        public readonly Model $actor,
        public readonly ?string $comment = null,
    ) {
        parent::__construct($request);
    }
}
