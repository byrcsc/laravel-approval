<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Enums;

enum CancellationIneligibility: string
{
    case RequestClosed = 'request_closed';
    case NotSubmitter = 'not_submitter';
}
