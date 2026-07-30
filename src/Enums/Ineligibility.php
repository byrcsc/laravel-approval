<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Enums;

/**
 * Why somebody may not decide a request.
 *
 * The order follows the eligibility check, from request state through the
 * active assignment.
 */
enum Ineligibility: string
{
    /** The request has been decided, withdrawn, or returned. */
    case RequestClosed = 'request_closed';

    /** No stage is currently waiting - the request is between states. */
    case NoActiveStage = 'no_active_stage';

    /** This actor has no assignment on the active stage. */
    case NotAnApprover = 'not_an_approver';

    /** Their assignment now belongs to a delegate. */
    case AssignmentDelegated = 'assignment_delegated';

    /** A decision has already been recorded for their assignment. */
    case DecisionAlreadyRecorded = 'decision_already_recorded';

    /** They submitted it, and this workflow does not allow that. */
    case SelfApproval = 'self_approval';
}
