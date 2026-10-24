<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Models;

use ByRcsc\LaravelApproval\ApprovalModels;
use ByRcsc\LaravelApproval\Database\Factories\ApprovalRequestAssignmentFactory;
use ByRcsc\LaravelApproval\Enums\ApproverSource;
use ByRcsc\LaravelApproval\Support\TableNames;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One approver's responsibility within a stage. Approvals are counted per
 * assignment, not per person, and delegating fills `delegated_to` without
 * clearing `approver` - who was asked is part of the record.
 */
class ApprovalRequestAssignment extends Model
{
    /** @use HasFactory<ApprovalRequestAssignmentFactory> */
    use HasFactory;

    protected $guarded = [];

    protected static function newFactory(): ApprovalRequestAssignmentFactory
    {
        return ApprovalRequestAssignmentFactory::new();
    }

    public function getTable(): string
    {
        return TableNames::for('assignments');
    }

    /**
     * @return BelongsTo<ApprovalRequestStage, $this>
     */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(ApprovalModels::requestStage(), 'stage_id');
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
     * The standing delegation that routed this assignment to its delegate, if
     * one did.
     *
     * @return BelongsTo<ApprovalDelegation, $this>
     */
    public function delegation(): BelongsTo
    {
        return $this->belongsTo(ApprovalModels::delegation(), 'delegation_id');
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
