<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Tests\Stubs;

use ByRcsc\LaravelApproval\Contracts\StageCondition;
use ByRcsc\LaravelApproval\Data\StageSnapshot;
use Illuminate\Database\Eloquent\Model;

final class AmountExceedsCondition implements StageCondition
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function passes(StageSnapshot $stage, Model $approvable, Model $submitter, array $config): bool
    {
        $amount = $approvable->getAttributeValue('amount');
        $threshold = $config['amount'] ?? 0;

        return is_numeric($amount) && is_numeric($threshold) && (float) $amount > (float) $threshold;
    }
}
