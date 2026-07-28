<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Models;

use ByRcsc\LaravelApproval\Enums\EscalationAction;
use ByRcsc\LaravelApproval\Enums\StepState;
use ByRcsc\LaravelApproval\Support\TableNames;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A workflow level, frozen onto a request at submit time. Everything needed to
 * run the level is copied here, so the level it came from can be edited,
 * reordered, or deleted without changing the rules this request is judged by.
 */
final class ApprovalRequestStep extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return TableNames::for('steps');
    }

    /**
     * @return BelongsTo<ApprovalRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'request_id');
    }

    /**
     * @return HasMany<ApprovalRequestStepApprover, $this>
     */
    public function approverSlots(): HasMany
    {
        return $this->hasMany(ApprovalRequestStepApprover::class, 'step_id');
    }

    /**
     * @return HasMany<ApprovalAction, $this>
     */
    public function actions(): HasMany
    {
        return $this->hasMany(ApprovalAction::class, 'step_id')
            ->orderBy('id');
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
            'condition_result' => 'boolean',
            'deadline_at' => 'datetime',
            'escalated_at' => 'datetime',
            'last_reminded_at' => 'datetime',
            'stranded_at' => 'datetime',
            'state' => StepState::class,
        ];
    }
}
