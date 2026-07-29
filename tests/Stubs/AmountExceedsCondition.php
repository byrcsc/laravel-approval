<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Tests\Stubs;

use ByRcsc\LaravelApproval\Contracts\LevelCondition;
use ByRcsc\LaravelApproval\Data\LevelSnapshot;
use Illuminate\Database\Eloquent\Model;

final class AmountExceedsCondition implements LevelCondition
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function passes(LevelSnapshot $level, Model $approvable, Model $submitter, array $config): bool
    {
        $amount = $approvable->getAttributeValue('amount');
        $threshold = $config['amount'] ?? 0;

        return is_numeric($amount) && is_numeric($threshold) && (float) $amount > (float) $threshold;
    }
}
