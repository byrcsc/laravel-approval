<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Enums;

/**
 * How an approver came to hold a slot. `Assigned` and `Resolved` are what the
 * submit-time snapshot produced; the rest record a deliberate intervention on a
 * request already in flight.
 */
enum ApproverSource: string
{
    case Assigned = 'assigned';
    case Resolved = 'resolved';
    case Delegated = 'delegated';
    case Reassigned = 'reassigned';
    case Escalated = 'escalated';
    case Resynced = 'resynced';
}
