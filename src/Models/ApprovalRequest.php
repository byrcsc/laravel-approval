<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Models;

use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Support\TableNames;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * One run of a workflow against one record.
 *
 * `workflow_id` is a reference for reporting only - the steps hanging off the
 * request are the snapshot that decides who approves what. Requests are never
 * reopened; resubmitting creates a fresh one that points back at this.
 */
final class ApprovalRequest extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return TableNames::for('requests');
    }

    protected static function booted(): void
    {
        self::creating(function (self $request): void {
            if (! is_string($request->getAttribute('ulid'))) {
                $request->setAttribute('ulid', (string) Str::ulid());
            }
        });
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function submitter(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<ApprovalWorkflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflow::class, 'workflow_id');
    }

    /**
     * @return HasMany<ApprovalRequestStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalRequestStep::class, 'request_id')
            ->orderBy('sequence');
    }

    /**
     * @return HasMany<ApprovalAction, $this>
     */
    public function actions(): HasMany
    {
        return $this->hasMany(ApprovalAction::class, 'request_id')
            ->orderBy('id');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function resubmittedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'resubmitted_from_id');
    }

    /**
     * A list rather than one record: nothing stops a replacement being rejected
     * too.
     *
     * @return HasMany<self, $this>
     */
    public function resubmissions(): HasMany
    {
        return $this->hasMany(self::class, 'resubmitted_from_id');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('state', RequestState::Pending);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeForApprovable(Builder $query, Model $approvable): void
    {
        $query
            ->where('approvable_type', $approvable->getMorphClass())
            ->where('approvable_id', $approvable->getKey());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => RequestState::class,
            'current_level_sequence' => 'integer',
            'new_attributes' => 'array',
            'original_attributes' => 'array',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
