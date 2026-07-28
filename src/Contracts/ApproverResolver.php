<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Contracts;

use ByRcsc\LaravelApproval\Data\LevelSnapshot;
use Illuminate\Database\Eloquent\Model;

/**
 * Turns "whoever manages this person" into actual records, at the moment a
 * request is submitted.
 *
 * Resolvers run once per submission and their answer is written into the step's
 * approver slots, so a resolver is a question asked at a point in time, never a
 * live query. That is deliberate: an approver list that changed underneath an
 * in-flight request would make the audit trail a guess.
 *
 * Implementations are resolved out of the container, so constructor injection
 * works. They must be side-effect free - a resolver that writes is a resolver
 * that runs during validation and leaves a mess behind when a later level turns
 * out to be misconfigured.
 */
interface ApproverResolver
{
    /**
     * @param  array<string, mixed>  $config  the level approver's `resolver_config` column
     * @return iterable<int, Model> may be empty; the engine decides whether that is fatal
     */
    public function resolve(LevelSnapshot $level, Model $approvable, Model $submitter, array $config): iterable;
}
