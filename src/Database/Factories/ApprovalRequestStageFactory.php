<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Database\Factories;

use ByRcsc\LaravelApproval\Enums\EscalationAction;
use ByRcsc\LaravelApproval\Enums\RejectionPolicy;
use ByRcsc\LaravelApproval\Enums\StageStatus;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApprovalRequestStage>
 */
final class ApprovalRequestStageFactory extends Factory
{
    protected $model = ApprovalRequestStage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'request_id' => ApprovalRequestFactory::new(),
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
            'condition_result' => true,
            'deadline_at' => null,
            'escalated_at' => null,
            'last_reminded_at' => null,
            'stranded_at' => null,
            'state' => StageStatus::Active,
        ];
    }

    public function queued(): self
    {
        return $this->state(['state' => StageStatus::Queued]);
    }

    public function approved(): self
    {
        return $this->state(['state' => StageStatus::Approved]);
    }

    public function rejected(): self
    {
        return $this->state(['state' => StageStatus::Rejected]);
    }

    public function returned(): self
    {
        return $this->state(['state' => StageStatus::Returned]);
    }

    public function conditionSkipped(): self
    {
        return $this->state([
            'state' => StageStatus::Skipped,
            'condition_result' => false,
        ]);
    }

    public function withApprovers(int $count = 1): self
    {
        return $this
            ->state(['required_approvals' => min(1, $count)])
            ->has(ApprovalRequestAssignmentFactory::new()->count($count), 'assignments');
    }
}
