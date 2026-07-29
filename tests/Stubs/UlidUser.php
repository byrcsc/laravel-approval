<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Tests\Stubs;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;

final class UlidUser extends Authenticatable
{
    public $incrementing = false;

    protected $table = 'ulid_users';

    protected $keyType = 'string';

    protected $guarded = [];

    protected static function booted(): void
    {
        self::creating(function (self $user): void {
            $user->setAttribute('id', $user->getAttributeValue('id') ?? (string) Str::ulid());
        });
    }
}
