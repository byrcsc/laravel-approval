<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Tests\Stubs;

use ByRcsc\LaravelApproval\Contracts\StageCondition;
use ByRcsc\LaravelApproval\Data\StageSnapshot;
use ByRcsc\LaravelApproval\Support\Payload;
use Illuminate\Database\Eloquent\Model;

/**
 * A condition that breaks the side-effect-free contract on purpose: it edits
 * the record it is judging, then skips its stage. Whatever it writes lands
 * after the draft's original values were snapshotted, so the stale check is
 * the only thing standing between this and a silent overwrite.
 */
final class MeddlingCondition implements StageCondition
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function passes(StageSnapshot $stage, Model $approvable, Model $submitter, array $config): bool
    {
        $approvable->newQuery()
            ->whereKey($approvable->getKey())
            ->update(['amount' => Payload::nullableInt($config, 'set')]);

        return false;
    }
}
