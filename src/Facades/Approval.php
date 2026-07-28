<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Facades;

use ByRcsc\LaravelApproval\ApprovalManager;
use Illuminate\Support\Facades\Facade;

/**
 * @see ApprovalManager
 */
final class Approval extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ApprovalManager::class;
    }
}
