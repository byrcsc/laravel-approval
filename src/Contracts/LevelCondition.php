<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Contracts;

use ByRcsc\LaravelApproval\Data\LevelSnapshot;
use Illuminate\Database\Eloquent\Model;

/**
 * Decides whether a workflow level applies to one submission. Conditions run
 * once, before the request is written; their result is frozen on the step.
 */
interface LevelCondition
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function passes(LevelSnapshot $level, Model $approvable, Model $submitter, array $config): bool;
}
