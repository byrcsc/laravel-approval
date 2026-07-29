<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Tests\Stubs;

use ByRcsc\LaravelApproval\Contracts\AutoSubmitsForApproval;

final class AutoPurchaseOrder extends PurchaseOrder implements AutoSubmitsForApproval {}
