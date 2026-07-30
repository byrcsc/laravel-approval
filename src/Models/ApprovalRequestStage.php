<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Models;

use ByRcsc\LaravelApproval\Database\Factories\ApprovalRequestStageFactory;
use ByRcsc\LaravelApproval\Enums\EscalationAction;
use ByRcsc\LaravelApproval\Enums\RejectionPolicy;
use ByRcsc\LaravelApproval\Enums\StageStatus;
use ByRcsc\LaravelApproval\Support\TableNames;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A workflow stage, frozen onto a request at submit time. Everything needed to
 * run the stage is copied here, so the stage it came from can be edited,
 * reordered, or deleted without changing the rules this request is judged by.
 */
final class ApprovalRequestStage extends Model
{
    /** @use HasFactory<ApprovalRequestStageFactory> */
    use HasFactory;

    protected $guarded = [];

    protected static function newFactory(): ApprovalRequestStageFactory
    {
        return ApprovalRequestStageFactory::new();
    }

    public function getTable(): string
    {
        return TableNames::for('request_stages');
    }

    /**
     * @return BelongsTo<ApprovalRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'request_id');
    }

    /**
     * @return HasMany<ApprovalRequestAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(ApprovalRequestAssignment::class, 'stage_id');
    }

    /**
     * @return HasMany<ApprovalAction, $this>
     */
    public function actions(): HasMany
    {
        return $this->hasMany(ApprovalAction::class, 'stage_id')
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
            'rejection_policy' => RejectionPolicy::class,
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
            'state' => StageStatus::class,
        ];
    }
}
