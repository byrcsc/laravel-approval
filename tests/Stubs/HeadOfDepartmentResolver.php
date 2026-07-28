<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Tests\Stubs;

use ByRcsc\LaravelApproval\Contracts\ApproverResolver;
use ByRcsc\LaravelApproval\Data\LevelSnapshot;
use ByRcsc\LaravelApproval\Support\Payload;
use Illuminate\Database\Eloquent\Model;

/**
 * What a host app's own resolver looks like: a query, run once, at submit time.
 */
class HeadOfDepartmentResolver implements ApproverResolver
{
    /**
     * Set by tests, in place of the org chart a real one would query.
     *
     * @var list<Model>
     */
    public static array $heads = [];

    /**
     * @param  array<string, mixed>  $config
     * @return iterable<int, Model>
     */
    public function resolve(LevelSnapshot $level, Model $approvable, Model $submitter, array $config): iterable
    {
        $limit = Payload::nullableInt($config, 'limit');

        return $limit === null ? self::$heads : array_slice(self::$heads, 0, $limit);
    }
}
