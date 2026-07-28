<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Models;

use ByRcsc\LaravelApproval\Support\TableNames;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One entry in a level's approver list: either a concrete record, or a resolver
 * class-string that produces approvers at submit time. Never both, never
 * neither - the engine enforces it, since no portable constraint expresses it.
 */
final class ApprovalWorkflowLevelApprover extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return TableNames::for('level_approvers');
    }

    /**
     * @return BelongsTo<ApprovalWorkflowLevel, $this>
     */
    public function level(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflowLevel::class, 'level_id');
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
