<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Contracts\StageCondition;
use ByRcsc\LaravelApproval\Data\StageSnapshot;
use ByRcsc\LaravelApproval\Exceptions\InvalidWorkflowException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;

final class EvaluateStageCondition
{
    public function __construct(private readonly Container $container) {}

    public function __invoke(
        StageSnapshot $snapshot,
        Model $approvable,
        Model $submitter,
        string $slug,
    ): bool {
        $condition = $snapshot->condition;

        if ($condition === null) {
            return true;
        }

        if (! class_exists($condition)) {
            throw InvalidWorkflowException::unknownCondition($slug, $snapshot->sequence, $condition);
        }

        if (! is_subclass_of($condition, StageCondition::class)) {
            throw InvalidWorkflowException::notACondition($slug, $snapshot->sequence, $condition);
        }

        /** @var StageCondition $instance */
        $instance = $this->container->make($condition);

        return $instance->passes($snapshot, $approvable, $submitter, $snapshot->conditionConfig);
    }
}
