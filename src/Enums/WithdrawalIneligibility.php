<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Enums;

enum WithdrawalIneligibility: string
{
    case RequestClosed = 'request_closed';
    case NotRequester = 'not_requester';
}
