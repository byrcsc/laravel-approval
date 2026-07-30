<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Enums;

enum StageStatus: string
{
    case Queued = 'queued';
    case Active = 'active';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Returned = 'returned';
    case Skipped = 'skipped';
}
