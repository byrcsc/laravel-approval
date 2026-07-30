<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Tests\Stubs;

use ByRcsc\LaravelApproval\Contracts\ApproverResolver;
use ByRcsc\LaravelApproval\Data\StageSnapshot;
use Illuminate\Database\Eloquent\Model;

/**
 * Remembers what the record looked like when the engine asked it who
 * approves, so a test can pin down which version of the record resolvers see.
 */
final class WitnessResolver implements ApproverResolver
{
    public static mixed $sawAmount = null;

    /**
     * @var list<Model>
     */
    public static array $approvers = [];

    /**
     * @param  array<string, mixed>  $config
     * @return iterable<int, Model>
     */
    public function resolve(StageSnapshot $stage, Model $approvable, Model $submitter, array $config): iterable
    {
        self::$sawAmount = $approvable->getAttributeValue('amount');

        return self::$approvers;
    }
}
