<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Models;

use ByRcsc\LaravelApproval\Database\Factories\ApprovalRequestStepApproverFactory;
use ByRcsc\LaravelApproval\Enums\ApproverSource;
use ByRcsc\LaravelApproval\Support\TableNames;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A seat at a step. Quorum is counted in slots, not people, and delegating
 * fills `delegated_to` without clearing `approver` - who was asked is part of
 * the record.
 */
final class ApprovalRequestStepApprover extends Model
{
    /** @use HasFactory<ApprovalRequestStepApproverFactory> */
    use HasFactory;

    protected $guarded = [];

    protected static function newFactory(): ApprovalRequestStepApproverFactory
    {
        return ApprovalRequestStepApproverFactory::new();
    }

    public function getTable(): string
    {
        return TableNames::for('step_approvers');
    }

    /**
     * @return BelongsTo<ApprovalRequestStep, $this>
     */
    public function step(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequestStep::class, 'step_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function approver(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Columns are named explicitly: Eloquent would otherwise derive them from
     * the camel-cased method name and go looking for `delegatedTo_type`.
     *
     * @return MorphTo<Model, $this>
     */
    public function delegatedTo(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'delegated_to_type', 'delegated_to_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => ApproverSource::class,
        ];
    }
}
