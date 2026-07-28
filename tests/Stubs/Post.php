<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;

/**
 * Stands in for the host app's approvable record. Plain for now; the
 * `Approvable` trait arrives with the submit path and lands here.
 */
class Post extends Model
{
    protected $table = 'posts';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published' => 'boolean',
        ];
    }
}
