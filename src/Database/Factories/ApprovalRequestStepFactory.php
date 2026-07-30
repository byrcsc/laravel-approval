<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Database\Factories;

use ByRcsc\LaravelApproval\Enums\EscalationAction;
use ByRcsc\LaravelApproval\Enums\RejectionPolicy;
use ByRcsc\LaravelApproval\Enums\StepState;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApprovalRequestStep>
 */
final class ApprovalRequestStepFactory extends Factory
{
    protected $model = ApprovalRequestStep::class;

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
            'escalation_strategy' => EscalationAction::Notify,
            'escalation_config' => null,
            'remind_every_minutes' => null,
            'condition' => null,
            'condition_config' => null,
            'condition_result' => true,
            'deadline_at' => null,
            'escalated_at' => null,
            'last_reminded_at' => null,
            'stranded_at' => null,
            'state' => StepState::Active,
        ];
    }

    public function queued(): self
    {
        return $this->state(['state' => StepState::Queued]);
    }

    public function approved(): self
    {
        return $this->state(['state' => StepState::Approved]);
    }

    public function rejected(): self
    {
        return $this->state(['state' => StepState::Rejected]);
    }

    public function returned(): self
    {
        return $this->state(['state' => StepState::Returned]);
    }

    public function conditionSkipped(): self
    {
        return $this->state([
            'state' => StepState::Skipped,
            'condition_result' => false,
        ]);
    }

    public function withApprovers(int $count = 1): self
    {
        return $this
            ->state(['required_approvals' => min(1, $count)])
            ->has(ApprovalRequestStepApproverFactory::new()->count($count), 'approverSlots');
    }
}
