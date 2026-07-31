<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Enums;

/**
 * An approver's response: approve, reject, or return for revision. Narrower than
 * {@see ActionType}, which also covers request events that are not decisions.
 *
 * Lets a controller build one `ApprovalDecision` from a form submission instead
 * of branching on the verdict at the call site.
 */
enum Verdict: string
{
    case Approve = 'approve';
    case Reject = 'reject';
    case Return = 'return';
}
