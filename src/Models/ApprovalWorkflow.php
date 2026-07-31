<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Models;

use ByRcsc\LaravelApproval\Database\Factories\ApprovalWorkflowFactory;
use ByRcsc\LaravelApproval\Support\TableNames;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
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
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    /**
     * The workflows registered against this approvable's type.
     *
     * Both the class name and the morph alias match, so the column may hold
     * either form - the same latitude the `approval.workflows` config map has.
     *
     * @param  Builder<self>  $query
     * @param  Model|class-string<Model>  $approvable
     */
    public function scopeForApprovable(Builder $query, Model|string $approvable): void
    {
        $query->whereIn('approvable_type', self::morphTypes($approvable));
    }

    /**
     * Most specific first: the highest priority wins, and the oldest workflow
     * breaks a tie so the order never depends on the database.
     *
     * @param  Builder<self>  $query
     */
    public function scopeInResolutionOrder(Builder $query): void
    {
        $query->orderByDesc('priority')->orderBy('id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'condition_config' => 'array',
            'priority' => 'integer',
            'settings' => 'array',
        ];
    }

    /**
     * @param  Model|class-string<Model>  $approvable
     * @return list<string>
     */
    private static function morphTypes(Model|string $approvable): array
    {
        if ($approvable instanceof Model) {
            return array_values(array_unique([$approvable::class, $approvable->getMorphClass()]));
        }

        $alias = Relation::getMorphAlias($approvable);

        return array_values(array_unique(is_string($alias) ? [$approvable, $alias] : [$approvable]));
    }
}
