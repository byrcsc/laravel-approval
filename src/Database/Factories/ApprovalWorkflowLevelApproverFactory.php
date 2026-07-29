<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Database\Factories;

use ByRcsc\LaravelApproval\Models\ApprovalWorkflow;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflowLevelApprover;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<ApprovalWorkflowLevelApprover>
 */
final class ApprovalWorkflowLevelApproverFactory extends Factory
{
    protected $model = ApprovalWorkflowLevelApprover::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'level_id' => ApprovalWorkflowLevelFactory::new(),
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
