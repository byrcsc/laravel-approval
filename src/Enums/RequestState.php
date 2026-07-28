<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Enums;

/**
 * `Pending` is the only open state; the rest are terminal. `Conflicted` means
 * the draft cleared every level but the record had changed underneath it, so
 * nothing was applied and a human has to decide what happens next.
 */
enum RequestState: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Conflicted = 'conflicted';
    case Cancelled = 'cancelled';
}
