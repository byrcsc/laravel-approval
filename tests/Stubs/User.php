<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Tests\Stubs;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Stands in for the host app's user: requester, approver, actor.
 */
class User extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];
}
