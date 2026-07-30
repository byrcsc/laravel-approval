<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Contracts;

use ByRcsc\LaravelApproval\Data\StageSnapshot;
use Illuminate\Database\Eloquent\Model;

/**
 * Turns an organizational rule - a role, a team, a reporting line - into the
 * actor models that may decide, at the moment a request is submitted.
 *
 * This is the supported mechanism for assigning a group: the package never
 * expands a container model into its members, so a resolver is what names the
 * individuals. Every returned model must be persisted, because an assignment
 * records an exact actor identity.
 *
 * A resolver runs once per submission and its answer is written into the stage's
 * assignments. It is a question asked at a point in time rather than a live
 * query, so an approver list that changes later does not rewrite an in-flight
 * request or its audit trail.
 *
 * Implementations are resolved from the container, so constructor injection
 * works. They must be free of side effects: resolvers also run during
 * definition validation, which may be abandoned if a later stage is invalid.
 */
interface ApproverResolver
{
    /**
     * @param  array<string, mixed>  $config  the stage approver's `resolver_config` column
     * @return iterable<int, Model> may be empty; the engine decides whether that is fatal
     */
    public function resolve(StageSnapshot $stage, Model $approvable, Model $submitter, array $config): iterable;
}
