<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Enums;

enum ApprovalMode: string
{
    case Explicit = 'explicit';
    case Guard = 'guard';
}
