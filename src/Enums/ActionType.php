<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Enums;

/**
 * Every decision and administrative change leaves one of these behind. Some
 * have no human actor: `Escalated`, `Applied`, `Conflicted`.
 */
enum ActionType: string
{
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Delegated = 'delegated';
    case Reassigned = 'reassigned';
    case Escalated = 'escalated';
    case Commented = 'commented';
    case Cancelled = 'cancelled';
    case Resubmitted = 'resubmitted';
    case Applied = 'applied';
    case Conflicted = 'conflicted';
    case Resynced = 'resynced';
}
