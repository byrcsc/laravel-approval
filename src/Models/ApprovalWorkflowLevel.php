<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Models;

use ByRcsc\LaravelApproval\Enums\EscalationAction;
use ByRcsc\LaravelApproval\Support\TableNames;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One rung of a workflow: who signs off, how many of them are needed, and what
 * happens if nobody does in time.
 */
final class ApprovalWorkflowLevel extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return TableNames::for('levels');
    }

    /**
     * @return BelongsTo<ApprovalWorkflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflow::class, 'workflow_id');
    }

    /**
     * @return HasMany<ApprovalWorkflowLevelApprover, $this>
     */
    public function approvers(): HasMany
    {
        return $this->hasMany(ApprovalWorkflowLevelApprover::class, 'level_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'required_approvals' => 'integer',
            'sla_minutes' => 'integer',
            'escalation_strategy' => EscalationAction::class,
            'escalation_config' => 'array',
            'remind_every_minutes' => 'integer',
            'condition_config' => 'array',
        ];
    }
}
