<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Models;

use ByRcsc\LaravelApproval\ApprovalModels;
use ByRcsc\LaravelApproval\Database\Factories\ApprovalWorkflowStageFactory;
use ByRcsc\LaravelApproval\Enums\EscalationAction;
use ByRcsc\LaravelApproval\Enums\RejectionPolicy;
use ByRcsc\LaravelApproval\Support\TableNames;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One rung of a workflow: who signs off, how many of them are needed, and what
 * happens if nobody does in time.
 */
class ApprovalWorkflowStage extends Model
{
    /** @use HasFactory<ApprovalWorkflowStageFactory> */
    use HasFactory;

    protected $guarded = [];

    protected static function newFactory(): ApprovalWorkflowStageFactory
    {
        return ApprovalWorkflowStageFactory::new();
    }

    public function getTable(): string
    {
        return TableNames::for('workflow_stages');
    }

    /**
     * @return BelongsTo<ApprovalWorkflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(ApprovalModels::workflow(), 'workflow_id');
    }

    /**
     * @return HasMany<ApprovalWorkflowStageApprover, $this>
     */
    public function approvers(): HasMany
    {
        return $this->hasMany(ApprovalModels::workflowStageApprover(), 'stage_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'required_approvals' => 'integer',
            'rejection_policy' => RejectionPolicy::class,
            'sla_minutes' => 'integer',
            'escalation_strategy' => EscalationAction::class,
            'escalation_config' => 'array',
            'remind_every_minutes' => 'integer',
            'condition_config' => 'array',
        ];
    }
}
