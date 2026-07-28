<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Tests\Stubs;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Paid = 'paid';
}
