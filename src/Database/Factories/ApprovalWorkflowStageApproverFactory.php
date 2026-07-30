<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Database\Factories;

use ByRcsc\LaravelApproval\Models\ApprovalWorkflow;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflowStageApprover;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<ApprovalWorkflowStageApprover>
 */
final class ApprovalWorkflowStageApproverFactory extends Factory
{
    protected $model = ApprovalWorkflowStageApprover::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stage_id' => ApprovalWorkflowStageFactory::new(),
            'approver_type' => ApprovalWorkflow::class,
            'approver_id' => ApprovalWorkflowFactory::new(),
            'resolver' => null,
            'resolver_config' => null,
        ];
    }

    public function forApprover(Model $approver): self
    {
        return $this->state([
            'approver_type' => $approver->getMorphClass(),
            'approver_id' => $approver->getKey(),
            'resolver' => null,
            'resolver_config' => null,
        ]);
    }

    /**
     * @param  class-string  $resolver
     * @param  array<string, mixed>  $config
     */
    public function resolvedBy(string $resolver, array $config = []): self
    {
        return $this->state([
            'approver_type' => null,
            'approver_id' => null,
            'resolver' => $resolver,
            'resolver_config' => $config === [] ? null : $config,
        ]);
    }
}
