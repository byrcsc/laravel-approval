<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Database\Factories;

use ByRcsc\LaravelApproval\Enums\EscalationAction;
use ByRcsc\LaravelApproval\Enums\RejectionPolicy;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflowStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApprovalWorkflowStage>
 */
final class ApprovalWorkflowStageFactory extends Factory
{
    protected $model = ApprovalWorkflowStage::class;

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
            'rejection_policy' => RejectionPolicy::ApprovalImpossible,
            'sla_minutes' => null,
            'escalation_strategy' => EscalationAction::RecordOnly,
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
            'name' => "Stage {$sequence}",
        ]);
    }

    public function withApprovers(int $count = 1): self
    {
        return $this->has(ApprovalWorkflowStageApproverFactory::new()->count($count), 'approvers');
    }
}
