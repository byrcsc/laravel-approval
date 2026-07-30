<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Events;

use ByRcsc\LaravelApproval\Models\ApprovalRequest;

/**
 * A closed request was revised and resubmitted. `$request` is the replacement,
 * already pending from its first applicable stage; `$previous` is the request
 * it replaces, whose state and history are unchanged apart from a conflicted
 * request being superseded.
 */
final class ApprovalResubmitted extends ApprovalEvent
{
    public function __construct(
        ApprovalRequest $request,
        public readonly ApprovalRequest $previous,
    ) {
        parent::__construct($request);
    }
}
