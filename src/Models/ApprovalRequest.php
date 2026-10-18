<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Models;

use ByRcsc\LaravelApproval\ApprovalModels;
use ByRcsc\LaravelApproval\Data\DelegationEligibility;
use ByRcsc\LaravelApproval\Data\Eligibility;
use ByRcsc\LaravelApproval\Data\StageProgress;
use ByRcsc\LaravelApproval\Data\TimelineEntry;
use ByRcsc\LaravelApproval\Data\WithdrawalEligibility;
use ByRcsc\LaravelApproval\Database\Factories\ApprovalRequestFactory;
use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Enums\StageStatus;
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
 * One execution of an approval workflow for one approvable record.
 *
 * `workflow_id` is a reporting reference only. The request's own stages are the
 * snapshot taken at submission, and they determine who approves what for the
 * lifetime of the request. Requests are never reopened: revising and
 * resubmitting creates a new request linked back to this one.
 */
class ApprovalRequest extends Model
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
    public function requester(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<ApprovalWorkflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(ApprovalModels::workflow(), 'workflow_id');
    }

    /**
     * @return HasMany<ApprovalRequestStage, $this>
     */
    public function stages(): HasMany
    {
        return $this->hasMany(ApprovalModels::requestStage(), 'request_id')
            ->orderBy('sequence');
    }

    /**
     * @return HasMany<ApprovalAction, $this>
     */
    public function actions(): HasMany
    {
        return $this->hasMany(ApprovalModels::action(), 'request_id')
            ->orderBy('id');
    }

    /**
     * @return HasMany<ApprovalAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(ApprovalModels::attachment(), 'request_id')
            ->orderBy('id');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function resubmittedFrom(): BelongsTo
    {
        return $this->belongsTo(ApprovalModels::request(), 'resubmitted_from_id');
    }

    /**
     * Requests created by revising this one. A list rather than a single record,
     * because a replacement can itself be returned or rejected and revised
     * again.
     *
     * @return HasMany<self, $this>
     */
    public function resubmissions(): HasMany
    {
        return $this->hasMany(ApprovalModels::request(), 'resubmitted_from_id');
    }

    /**
     * The workflow's slug, or a request-scoped placeholder when the workflow row
     * has been deleted. Intended for display and log messages; not a valid
     * argument to workflow lookup.
     */
    public function workflowSlug(): string
    {
        $workflow = $this->workflow;

        return $workflow === null
            ? 'request-'.Attributes::string($this, 'id')
            : Attributes::string($workflow, 'slug');
    }

    public function wasRequestedBy(Model $actor): bool
    {
        $key = $actor->getKey();

        return Attributes::nullableString($this, 'requester_type') === $actor->getMorphClass()
            && Attributes::nullableString($this, 'requester_id') === (is_scalar($key) ? (string) $key : '');
    }

    public function currentStage(): ?ApprovalRequestStage
    {
        if ($this->relationLoaded('stages')) {
            /** @var ApprovalRequestStage|null */
            return $this->stages->first(
                fn (ApprovalRequestStage $stage): bool => Attributes::enum($stage, 'state', StageStatus::class) === StageStatus::Active,
            );
        }

        /** @var ApprovalRequestStage|null */
        return $this->stages()->where('state', StageStatus::Active->value)->first();
    }

    /**
     * Progress for the stage identified as current on this request.
     */
    public function stageProgress(): ?StageProgress
    {
        $sequence = Attributes::nullableInt($this, 'current_stage_sequence');

        if ($sequence === null) {
            return null;
        }

        $stage = $this->relationLoaded('stages')
            ? $this->stages->first(
                fn (ApprovalRequestStage $candidate): bool => Attributes::int($candidate, 'sequence') === $sequence,
            )
            : $this->stages()->where('sequence', $sequence)->first();

        return $stage instanceof ApprovalRequestStage ? StageProgress::for($stage) : null;
    }

    /**
     * The assignment holders the current stage is still waiting on. Holders
     * who have already responded are excluded; {@see currentStageApprovers()}
     * lists every holder.
     *
     * @return Collection<int, Model>
     */
    public function pendingApprovers(): Collection
    {
        $stage = $this->currentStage();
        $holders = $stage === null ? [] : StageProgress::for($stage)->pendingAssignmentHolders();

        return self::models($holders);
    }

    /**
     * Every assignment holder for the current stage, whether or not they have
     * already responded.
     *
     * @return Collection<int, Model>
     */
    public function currentStageApprovers(): Collection
    {
        $stage = $this->currentStage();
        $holders = $stage === null ? [] : StageProgress::for($stage)->assignmentHolders();

        return self::models($holders);
    }

    /**
     * The stage-by-stage timeline of this request only. Earlier and later
     * requests in a linked chain keep their own timelines, reachable through
     * `resubmittedFrom()` and `resubmissions()`.
     *
     * @return Collection<int, TimelineEntry>
     */
    public function timeline(): Collection
    {
        $this->loadMissing([
            'stages.assignments.approver',
            'stages.assignments.delegatedTo',
            'stages.actions',
        ]);

        return $this->stages->map(function (ApprovalRequestStage $stage): TimelineEntry {
            $stageState = Attributes::enum($stage, 'state', StageStatus::class) ?? StageStatus::Queued;
            $state = $stageState === StageStatus::Skipped && ! Attributes::bool($stage, 'condition_result')
                ? TimelineState::ConditionSkipped
                : TimelineState::from($stageState->value);

            return new TimelineEntry(
                $stage,
                $state,
                collect(StageProgress::for($stage)->assignmentHolders()),
                $stage->actions,
            );
        })->values();
    }

    /**
     * Whether this actor may decide this request, and if not, why not.
     *
     * This and the two eligibility questions below are the request's
     * authorization surface. Recording a decision, withdrawing, and delegating
     * are commands, and live on the `Approval` facade.
     */
    public function approvalEligibility(Model $actor): Eligibility
    {
        return Approval::eligibility($this, $actor);
    }

    public function canBeApprovedBy(Model $actor): bool
    {
        return $this->approvalEligibility($actor)->eligible;
    }

    public function withdrawalEligibility(Model $actor): WithdrawalEligibility
    {
        return Approval::withdrawalEligibility($this, $actor);
    }

    public function canBeWithdrawnBy(Model $actor): bool
    {
        return $this->withdrawalEligibility($actor)->eligible;
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
     * Whether this actor has standing in the request: they requested it, or they
     * are named on one of its assignments.
     *
     * Wider than eligibility and not time-bound. An approver who decided a
     * first stage last week is still a participant, which is what a read
     * authorization check needs to know. Applications needing a wider audience,
     * such as auditors or department heads, should widen it in their own policy.
     */
    public function involves(Model $actor): bool
    {
        $type = $actor->getMorphClass();
        $id = $actor->getKey();

        $isRequester = Attributes::nullableString($this, 'requester_type') === $type
            && Attributes::nullableString($this, 'requester_id') === (is_scalar($id) ? (string) $id : '');

        if ($isRequester) {
            return true;
        }

        return $this->stages()
            ->whereHas('assignments', function (Builder $assignment) use ($type, $id): void {
                $assignment
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
     * Requests that still require a decision or conflict resolution.
     *
     * @param  Builder<self>  $query
     */
    public function scopeUnresolved(Builder $query): void
    {
        $query->whereIn('state', [
            RequestState::Pending->value,
            RequestState::Conflicted->value,
        ]);
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
     * Open requests whose active stage is past its deadline.
     *
     * Independent of whether the escalation policy has run, which is recorded as
     * `escalated_at` on the stage. A stage remains overdue until it is decided.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOverdue(Builder $query): void
    {
        $query->pending()->whereHas('stages', function (Builder $stage): void {
            $stage->where('state', StageStatus::Active->value)
                ->whereNotNull('deadline_at')
                ->where('deadline_at', '<=', Date::now());
        });
    }

    /**
     * The inbox query: pending requests whose active stage is waiting on this
     * user and which the user has not already approved.
     *
     * A delegated assignment belongs to the delegate rather than the delegating
     * approver, so it appears in exactly one inbox. Excluding assignments the
     * user has already approved keeps a request out of their inbox while the
     * stage waits on the remaining approvers.
     *
     * @param  Builder<self>  $query
     */
    public function scopeAwaitingUser(Builder $query, Model $user): void
    {
        self::constrainAwaitingUser($query, $user);
    }

    /**
     * The constraint behind {@see self::scopeAwaitingUser()}, for callers that
     * hold a request query rather than a scope.
     *
     * @param  Builder<self>  $query
     */
    public static function constrainAwaitingUser(Builder $query, Model $user): void
    {
        $type = $user->getMorphClass();
        $id = $user->getKey();

        $query->where('state', RequestState::Pending->value)->whereHas('stages', function (Builder $stage) use ($type, $id): void {
            $stage
                ->where('state', StageStatus::Active->value)
                ->whereHas('assignments', function (Builder $assignment) use ($type, $id): void {
                    $assignment->where(function (Builder $held) use ($type, $id): void {
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
     * Eager-load everything used by current-stage summaries and timelines.
     *
     * @param  Builder<self>  $query
     */
    public function scopeWithProgress(Builder $query): void
    {
        $query->with([
            'stages.assignments.approver',
            'stages.assignments.delegatedTo',
            'stages.actions',
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => RequestState::class,
            'current_stage_sequence' => 'integer',
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
