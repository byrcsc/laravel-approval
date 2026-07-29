<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Enums;

/**
 * Steps run in sequence, with at most one `Active` at a time. `Skipped` is what
 * the steps after a rejection become: they never ran, which is a different fact
 * from having been decided.
 */
enum StepState: string
{
    case Queued = 'queued';
    case Active = 'active';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Returned = 'returned';
    case Skipped = 'skipped';
}
