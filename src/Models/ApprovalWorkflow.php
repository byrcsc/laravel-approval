<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Models;

use ByRcsc\LaravelApproval\Database\Factories\ApprovalWorkflowFactory;
use ByRcsc\LaravelApproval\Support\TableNames;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Freely editable: requests already in flight run off their own snapshot, so
 * changes here only affect what is submitted next.
 */
final class ApprovalWorkflow extends Model
{
    /** @use HasFactory<ApprovalWorkflowFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = [];

    protected static function newFactory(): ApprovalWorkflowFactory
    {
        return ApprovalWorkflowFactory::new();
    }

    public function getTable(): string
    {
        return TableNames::for('workflows');
    }

    /**
     * @return HasMany<ApprovalWorkflowStage, $this>
     */
    public function stages(): HasMany
    {
        return $this->hasMany(ApprovalWorkflowStage::class, 'workflow_id')
            ->orderBy('sequence');
    }

    /**
     * @return HasMany<ApprovalRequest, $this>
     */
    public function requests(): HasMany
    {
        return $this->hasMany(ApprovalRequest::class, 'workflow_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'settings' => 'array',
        ];
    }
}
