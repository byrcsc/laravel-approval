<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Contracts\WorkflowCondition;
use ByRcsc\LaravelApproval\Exceptions\InvalidWorkflowException;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflow;
use ByRcsc\LaravelApproval\Support\Attributes;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;

/**
 * Whether one workflow applies to one submission. A workflow with no condition
 * applies to everything it is registered against.
 */
final class EvaluateWorkflowCondition
{
    public function __construct(private readonly Container $container) {}

    public function __invoke(ApprovalWorkflow $workflow, Model $approvable, ?Model $requester): bool
    {
        $condition = Attributes::nullableString($workflow, 'condition');

        if ($condition === null) {
            return true;
        }

        $slug = Attributes::string($workflow, 'slug');

        if (! class_exists($condition)) {
            throw InvalidWorkflowException::unknownWorkflowCondition($slug, $condition);
        }

        if (! is_subclass_of($condition, WorkflowCondition::class)) {
            throw InvalidWorkflowException::notAWorkflowCondition($slug, $condition);
        }

        /** @var WorkflowCondition $instance */
        $instance = $this->container->make($condition);

        return $instance->applies($workflow, $approvable, $requester, Attributes::object($workflow, 'condition_config'));
    }
}
