<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Enums;

/**
 * The two resolutions available for a conflicted request: apply the draft over
 * the record's current values, or discard the draft.
 *
 * There is no merge resolution. Choosing between two competing changes depends on
 * application-specific meaning the package does not have, so a conflicted
 * request stays open until an administrator chooses one of these two.
 */
enum ConflictResolution: string
{
    case Reapply = 'reapply';
    case Discard = 'discard';
}
