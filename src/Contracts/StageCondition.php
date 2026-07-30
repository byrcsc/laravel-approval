<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Contracts;

use ByRcsc\LaravelApproval\Data\StageSnapshot;
use Illuminate\Database\Eloquent\Model;

/**
 * Decides whether a workflow stage applies to one submission. Conditions run
 * once, before the request is written; their result is frozen on the stage.
 */
interface StageCondition
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function passes(StageSnapshot $stage, Model $approvable, Model $submitter, array $config): bool;
}
