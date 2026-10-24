<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Models;

use ByRcsc\LaravelApproval\ApprovalModels;
use ByRcsc\LaravelApproval\Database\Factories\ApprovalWorkflowStageApproverFactory;
use ByRcsc\LaravelApproval\Support\TableNames;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One entry in a stage's approver list: either a concrete record, or a resolver
 * class-string that produces approvers at submit time. Never both, never
 * neither - the engine enforces it, since no portable constraint expresses it.
 */
class ApprovalWorkflowStageApprover extends Model
{
    /** @use HasFactory<ApprovalWorkflowStageApproverFactory> */
    use HasFactory;

    protected $guarded = [];

    protected static function newFactory(): ApprovalWorkflowStageApproverFactory
    {
        return ApprovalWorkflowStageApproverFactory::new();
    }

    public function getTable(): string
    {
        return TableNames::for('workflow_stage_approvers');
    }

    /**
     * @return BelongsTo<ApprovalWorkflowStage, $this>
     */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(ApprovalModels::workflowStage(), 'stage_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function approver(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'resolver_config' => 'array',
        ];
    }
}
