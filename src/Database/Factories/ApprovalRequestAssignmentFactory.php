<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Database\Factories;

use ByRcsc\LaravelApproval\Enums\ApproverSource;
use ByRcsc\LaravelApproval\Models\ApprovalRequestAssignment;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflow;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<ApprovalRequestAssignment>
 */
final class ApprovalRequestAssignmentFactory extends Factory
{
    protected $model = ApprovalRequestAssignment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stage_id' => ApprovalRequestStageFactory::new(),
            'approver_type' => ApprovalWorkflow::class,
            'approver_id' => ApprovalWorkflowFactory::new(),
            'delegated_to_type' => null,
            'delegated_to_id' => null,
            'source' => ApproverSource::Assigned,
        ];
    }

    public function forApprover(Model $approver): self
    {
        return $this->state([
            'approver_type' => $approver->getMorphClass(),
            'approver_id' => $approver->getKey(),
        ]);
    }

    public function delegatedTo(Model $delegate): self
    {
        return $this->state([
            'delegated_to_type' => $delegate->getMorphClass(),
            'delegated_to_id' => $delegate->getKey(),
            'source' => ApproverSource::Delegated,
        ]);
    }
}
