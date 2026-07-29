<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Enums;

/**
 * `Pending` is the only open state; the rest are terminal. `Returned` may
 * produce a linked successor through resume. `Conflicted` means
 * the draft cleared every level but the record had changed underneath it, so
 * nothing was applied and a human has to decide what happens next.
 */
enum RequestState: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Returned = 'returned';
    case Conflicted = 'conflicted';
    case Cancelled = 'cancelled';
}
