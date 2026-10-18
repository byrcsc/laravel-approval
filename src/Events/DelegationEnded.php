<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Events;

use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestAssignment;
use Illuminate\Database\Eloquent\Model;

/**
 * A standing delegation ended or was revoked, and an assignment it had routed
 * went back to its approver. Fired only for assignments nobody had decided yet.
 */
final class DelegationEnded extends ApprovalEvent
{
    public function __construct(
        ApprovalRequest $request,
        public readonly ApprovalRequestAssignment $assignment,
        public readonly ?Model $approver,
        public readonly ?Model $delegate,
    ) {
        parent::__construct($request);
    }
}
