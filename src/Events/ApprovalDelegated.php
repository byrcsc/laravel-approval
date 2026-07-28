<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Events;

use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStepApprover;
use Illuminate\Database\Eloquent\Model;

/**
 * An approver handed their seat to somebody else. The seat is the same seat;
 * only who may fill it has changed.
 */
final class ApprovalDelegated extends ApprovalEvent
{
    public function __construct(
        ApprovalRequest $request,
        public readonly ApprovalRequestStepApprover $seat,
        public readonly Model $approver,
        public readonly Model $delegate,
        public readonly ?string $comment = null,
    ) {
        parent::__construct($request);
    }
}
