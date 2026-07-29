<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Database\Factories;

use ByRcsc\LaravelApproval\Enums\EscalationAction;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflowLevel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApprovalWorkflowLevel>
 */
final class ApprovalWorkflowLevelFactory extends Factory
{
    protected $model = ApprovalWorkflowLevel::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_id' => ApprovalWorkflowFactory::new(),
            'sequence' => 1,
            'name' => 'Approval',
            'required_approvals' => 1,
            'sla_minutes' => null,
            'escalation_strategy' => EscalationAction::Notify,
            'escalation_config' => null,
            'remind_every_minutes' => null,
            'condition' => null,
            'condition_config' => null,
        ];
    }

    public function atSequence(int $sequence): self
    {
        return $this->state([
            'sequence' => $sequence,
            'name' => "Level {$sequence}",
        ]);
    }

    public function withApprovers(int $count = 1): self
    {
        return $this->has(ApprovalWorkflowLevelApproverFactory::new()->count($count), 'approvers');
    }
}
