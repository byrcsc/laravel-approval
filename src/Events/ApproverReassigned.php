<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Events;

use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStepApprover;
use Illuminate\Database\Eloquent\Model;

/**
 * An administrator replaced who a seat belongs to - the "they left the company"
 * case, rather than the "I am on holiday" one that {@see ApprovalDelegated}
 * covers.
 *
 * `$from` is null when the record that held the seat has itself been deleted.
 */
final class ApproverReassigned extends ApprovalEvent
{
    public function __construct(
        ApprovalRequest $request,
        public readonly ApprovalRequestStepApprover $seat,
        public readonly ?Model $from,
        public readonly Model $to,
        public readonly ?Model $actor = null,
    ) {
        parent::__construct($request);
    }
}
