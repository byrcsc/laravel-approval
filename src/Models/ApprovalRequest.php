<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Models;

use ByRcsc\LaravelApproval\Data\Ballot;
use ByRcsc\LaravelApproval\Data\CancellationEligibility;
use ByRcsc\LaravelApproval\Data\DelegationEligibility;
use ByRcsc\LaravelApproval\Data\TimelineEntry;
use ByRcsc\LaravelApproval\Database\Factories\ApprovalRequestFactory;
use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Enums\StepState;
use ByRcsc\LaravelApproval\Enums\TimelineState;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Support\Attributes;
use ByRcsc\LaravelApproval\Support\TableNames;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

/**
 * One run of a workflow against one record.
 *
 * `workflow_id` is a reference for reporting only - the steps hanging off the
 * request are the snapshot that decides who approves what. Requests are never
 * reopened; resubmitting or resuming creates a fresh one that points back at
 * this.
 */
final class ApprovalRequest extends Model
{
    /** @use HasFactory<ApprovalRequestFactory> */
    use HasFactory;

    protected $guarded = [];

    protected static function newFactory(): ApprovalRequestFactory
    {
        return ApprovalRequestFactory::new();
    }

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
     * @return HasMany<ApprovalAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(ApprovalAttachment::class, 'request_id')
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
     * The workflow's slug, or a request-scoped placeholder when the workflow
     * row is gone. For messages, never for lookups.
     */
    public function workflowName(): string
    {
        $workflow = $this->workflow;

        return $workflow === null
            ? 'request-'.Attributes::string($this, 'id')
            : Attributes::string($workflow, 'slug');
    }

    public function wasSubmittedBy(Model $actor): bool
    {
        $key = $actor->getKey();

        return Attributes::nullableString($this, 'submitter_type') === $actor->getMorphClass()
            && Attributes::nullableString($this, 'submitter_id') === (is_scalar($key) ? (string) $key : '');
    }

    public function currentStep(): ?ApprovalRequestStep
    {
        if ($this->relationLoaded('steps')) {
            /** @var ApprovalRequestStep|null */
            return $this->steps->first(
                fn (ApprovalRequestStep $step): bool => Attributes::enum($step, 'state', StepState::class) === StepState::Active,
            );
        }

        /** @var ApprovalRequestStep|null */
        return $this->steps()->where('state', StepState::Active->value)->first();
    }

    /**
     * @return Collection<int, Model>
     */
    public function pendingApprovers(): Collection
    {
        $step = $this->currentStep();
        $holders = $step === null ? [] : Ballot::for($step)->holders();

        return self::models($holders);
    }

    /**
     * @return Collection<int, TimelineEntry>
     */
    public function timeline(): Collection
    {
        $this->loadMissing([
            'steps.approverSlots.approver',
            'steps.approverSlots.delegatedTo',
            'steps.actions',
        ]);

        return $this->steps->map(function (ApprovalRequestStep $step): TimelineEntry {
            $stepState = Attributes::enum($step, 'state', StepState::class) ?? StepState::Queued;
            $state = $stepState === StepState::Skipped && ! Attributes::bool($step, 'condition_result')
                ? TimelineState::ConditionSkipped
                : TimelineState::from($stepState->value);

            return new TimelineEntry(
                $step,
                $state,
                collect(Ballot::for($step)->holders()),
                $step->actions,
            );
        })->values();
    }

    public function cancellationEligibility(Model $actor): CancellationEligibility
    {
        return Approval::cancellationEligibility($this, $actor);
    }

    public function canBeCancelledBy(Model $actor): bool
    {
        return $this->cancellationEligibility($actor)->eligible;
    }

    public function delegationEligibility(Model $actor, ?Model $delegate = null): DelegationEligibility
    {
        return Approval::delegationEligibility($this, $actor, $delegate);
    }

    public function canBeDelegatedBy(Model $actor, ?Model $delegate = null): bool
    {
        return $this->delegationEligibility($actor, $delegate)->eligible;
    }

    /**
     * Whether this actor has any standing in the request at all: they submitted
     * it, or they are named on one of its seats.
     *
     * Deliberately wider than eligibility and deliberately not time-bound - a
     * level-one approver who voted last week is still a participant in the
     * request, which is the question a "may they read this?" check is asking.
     * Hosts that want a wider audience still (an auditor, a department head)
     * should widen it in their own policy rather than here.
     */
    public function involves(Model $actor): bool
    {
        $type = $actor->getMorphClass();
        $id = $actor->getKey();

        $isSubmitter = Attributes::nullableString($this, 'submitter_type') === $type
            && Attributes::nullableString($this, 'submitter_id') === (is_scalar($id) ? (string) $id : '');

        if ($isSubmitter) {
            return true;
        }

        return $this->steps()
            ->whereHas('approverSlots', function (Builder $slot) use ($type, $id): void {
                $slot
                    ->where(function (Builder $named) use ($type, $id): void {
                        $named->where('approver_type', $type)->where('approver_id', $id);
                    })
                    ->orWhere(function (Builder $delegated) use ($type, $id): void {
                        $delegated->where('delegated_to_type', $type)->where('delegated_to_id', $id);
                    });
            })
            ->exists();
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
     * Open requests whose active level is past its deadline.
     *
     * Says nothing about whether the escalation policy has run - that is
     * `escalated_at` on the step, and a level stays overdue for as long as
     * nobody decides it.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOverdue(Builder $query): void
    {
        $query->pending()->whereHas('steps', function (Builder $step): void {
            $step->where('state', StepState::Active->value)
                ->whereNotNull('deadline_at')
                ->where('deadline_at', '<=', Date::now());
        });
    }

    /**
     * The inbox query.
     *
     * A seat that has been delegated belongs to the delegate and not to the
     * person who delegated it, so it shows in exactly one inbox. The
     * already-voted exclusion is what keeps a request off somebody's list once
     * they have dealt with it, while the level waits on their colleagues.
     *
     * @param  Builder<self>  $query
     */
    public function scopeAwaitingUser(Builder $query, Model $user): void
    {
        self::constrainAwaitingUser($query, $user);
    }

    /**
     * Shared with approvable-model inbox scopes.
     *
     * @param  Builder<self>  $query
     */
    public static function constrainAwaitingUser(Builder $query, Model $user): void
    {
        $type = $user->getMorphClass();
        $id = $user->getKey();

        $query->where('state', RequestState::Pending->value)->whereHas('steps', function (Builder $step) use ($type, $id): void {
            $step
                ->where('state', StepState::Active->value)
                ->whereHas('approverSlots', function (Builder $slot) use ($type, $id): void {
                    $slot->where(function (Builder $held) use ($type, $id): void {
                        $held
                            ->where(function (Builder $own) use ($type, $id): void {
                                $own->whereNull('delegated_to_id')
                                    ->where('approver_type', $type)
                                    ->where('approver_id', $id);
                            })
                            ->orWhere(function (Builder $delegated) use ($type, $id): void {
                                $delegated->where('delegated_to_type', $type)
                                    ->where('delegated_to_id', $id);
                            });
                    });
                })
                ->whereDoesntHave('actions', function (Builder $action) use ($type, $id): void {
                    $action->where('action', ActionType::Approved->value)
                        ->where('actor_type', $type)
                        ->where('actor_id', $id);
                });
        });
    }

    /**
     * Eager-load everything used by current-step summaries and timelines.
     *
     * @param  Builder<self>  $query
     */
    public function scopeWithProgress(Builder $query): void
    {
        $query->with([
            'steps.approverSlots.approver',
            'steps.approverSlots.delegatedTo',
            'steps.actions',
        ]);
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

    /**
     * @template TModel of Model
     *
     * @param  list<TModel>  $models
     * @return Collection<int, TModel>
     */
    private static function models(array $models): Collection
    {
        return collect($models)->values();
    }
}
