<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Contracts\LevelCondition;
use ByRcsc\LaravelApproval\Data\LevelSnapshot;
use ByRcsc\LaravelApproval\Exceptions\InvalidWorkflowException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;

final class EvaluateLevelCondition
{
    public function __construct(private readonly Container $container) {}

    public function __invoke(
        LevelSnapshot $snapshot,
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

        if (! is_subclass_of($condition, LevelCondition::class)) {
            throw InvalidWorkflowException::notACondition($slug, $snapshot->sequence, $condition);
        }

        /** @var LevelCondition $instance */
        $instance = $this->container->make($condition);

        return $instance->passes($snapshot, $approvable, $submitter, $snapshot->conditionConfig);
    }
}
