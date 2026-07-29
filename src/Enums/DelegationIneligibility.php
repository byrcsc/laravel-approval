<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Enums;

enum DelegationIneligibility: string
{
    case RequestClosed = 'request_closed';
    case NoActiveStep = 'no_active_step';
    case NotAnApprover = 'not_an_approver';
    case SeatDelegated = 'seat_delegated';
    case AlreadyVoted = 'already_voted';
    case ApproverMissing = 'approver_missing';
    case CannotDelegateToSelf = 'cannot_delegate_to_self';
    case DelegateAlreadySeated = 'delegate_already_seated';
    case DelegateMissing = 'delegate_missing';
}
