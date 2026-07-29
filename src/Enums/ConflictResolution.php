<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Enums;

/**
 * The two ways out of a conflicted request: apply the draft over whatever the
 * record now holds, or throw the draft away.
 *
 * There is no third option that merges them. Deciding which of two changes to
 * a purchase order is the right one needs a person who understands purchase
 * orders, and this package is not that person - it just holds the request open
 * until somebody says.
 */
enum ConflictResolution: string
{
    case Reapply = 'reapply';
    case Discard = 'discard';
}
