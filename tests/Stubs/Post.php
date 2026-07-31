<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Tests\Stubs;

use ByRcsc\LaravelApproval\Concerns\Approvable;
use Illuminate\Database\Eloquent\Model;

/**
 * Stands in for the host app's approvable record, taking the trait exactly as
 * it ships: no workflow declared, no attribute allow-list, no requester.
 * {@see PurchaseOrder} is the same idea with all three seams overridden.
 */
class Post extends Model
{
    use Approvable;

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
