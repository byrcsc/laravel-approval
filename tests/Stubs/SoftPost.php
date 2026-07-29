<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Tests\Stubs;

use Illuminate\Database\Eloquent\SoftDeletes;

class SoftPost extends Post
{
    use SoftDeletes;
}
