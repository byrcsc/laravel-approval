<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Database\Factories;

use ByRcsc\LaravelApproval\Actions\RecordAction;
use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Enums\StageStatus;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflow;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\Sequence;

/**
 * @extends Factory<ApprovalRequest>
 */
final class ApprovalRequestFactory extends Factory
{
    protected $model = ApprovalRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'approvable_type' => ApprovalWorkflow::class,
            'approvable_id' => ApprovalWorkflowFactory::new(),
            'workflow_id' => ApprovalWorkflowFactory::new(),
            'state' => RequestState::Pending,
            'current_stage_sequence' => 1,
            'new_attributes' => null,
            'original_attributes' => null,
            'submitter_type' => ApprovalWorkflow::class,
            'submitter_id' => ApprovalWorkflowFactory::new(),
            'submitted_at' => now(),
            'completed_at' => null,
            'audit_head_hash' => null,
        ];
    }

    public function pending(): self
    {
        return $this->state([
            'state' => RequestState::Pending,
            'completed_at' => null,
        ]);
    }

    public function approved(): self
    {
        return $this->closed(RequestState::Approved);
    }

    public function rejected(): self
    {
        return $this->closed(RequestState::Rejected);
    }

    public function returned(): self
    {
        return $this->closed(RequestState::Returned);
    }

    public function cancelled(): self
    {
        return $this->closed(RequestState::Cancelled);
    }

    public function conflicted(): self
    {
        return $this->state([
            'state' => RequestState::Conflicted,
            'completed_at' => null,
        ]);
    }

    public function withdrawn(): self
    {
        return $this->closed(RequestState::Withdrawn);
    }

    public function superseded(): self
    {
        return $this->closed(RequestState::Superseded);
    }

    public function withStages(int $count = 1): self
    {
        return $this->has(
            ApprovalRequestStageFactory::new()
                ->withApprovers()
                ->count($count)
                ->sequence(fn (Sequence $sequence): array => [
                    'sequence' => $sequence->index + 1,
                    'name' => 'Stage '.($sequence->index + 1),
                    'state' => $sequence->index === 0 ? StageStatus::Active : StageStatus::Queued,
                ]),
            'stages',
        );
    }

    public function withActions(int $count = 1): self
    {
        return $this->afterCreating(function (ApprovalRequest $request) use ($count): void {
            for ($index = 0; $index < $count; $index++) {
                app(RecordAction::class)(
                    request: $request,
                    action: ActionType::Submitted,
                    metadata: ['factory_sequence' => $index + 1],
                );
            }
        });
    }

    private function closed(RequestState $state): self
    {
        return $this->state([
            'state' => $state,
            'completed_at' => now(),
        ]);
    }
}
