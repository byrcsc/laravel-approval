<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Enums;

enum DelegationIneligibility: string
{
    case RequestClosed = 'request_closed';
    case NoActiveStage = 'no_active_stage';
    case NotAnApprover = 'not_an_approver';
    case AssignmentDelegated = 'assignment_delegated';
    case DecisionAlreadyRecorded = 'decision_already_recorded';
    case ApproverMissing = 'approver_missing';
    case CannotDelegateToSelf = 'cannot_delegate_to_self';
    case DelegateAlreadyAssigned = 'delegate_already_assigned';
    case DelegateMissing = 'delegate_missing';
}
