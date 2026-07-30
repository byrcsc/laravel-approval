<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Tests\Stubs;

use ByRcsc\LaravelApproval\Contracts\StageCondition;
use ByRcsc\LaravelApproval\Data\StageSnapshot;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;

/**
 * A condition with a constructor dependency, to prove conditions come out of
 * the container rather than out of `new`.
 */
final class ThresholdFromConfigCondition implements StageCondition
{
    public function __construct(private readonly Repository $config) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public function passes(StageSnapshot $stage, Model $approvable, Model $requester, array $config): bool
    {
        $amount = $approvable->getAttributeValue('amount');
        $threshold = $this->config->get('approval-tests.threshold', 0);

        return is_numeric($amount) && is_numeric($threshold) && (float) $amount > (float) $threshold;
    }
}
