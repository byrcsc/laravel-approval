<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Events;

use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestAssignment;
use Illuminate\Database\Eloquent\Model;

/**
 * An administrator replaced who an assignment belongs to - the "they left the company"
 * case, rather than the "I am on holiday" one that {@see ApprovalDelegated}
 * covers.
 *
 * `$from` is null when the record that held the assignment has itself been deleted.
 */
final class ApproverReassigned extends ApprovalEvent
{
    public function __construct(
        ApprovalRequest $request,
        public readonly ApprovalRequestAssignment $assignment,
        public readonly ?Model $from,
        public readonly Model $to,
        public readonly Model $actor,
    ) {
        parent::__construct($request);
    }
}
