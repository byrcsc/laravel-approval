<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Concerns;

use ByRcsc\LaravelApproval\Actions\SystemCloseRequest;
use ByRcsc\LaravelApproval\Contracts\AutoSubmitsForApproval;
use ByRcsc\LaravelApproval\Data\AutoSubmission;
use ByRcsc\LaravelApproval\Data\Draft;
use ByRcsc\LaravelApproval\Data\Eligibility;
use ByRcsc\LaravelApproval\Data\TimelineEntry;
use ByRcsc\LaravelApproval\Enums\ApprovalMode;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Exceptions\ApprovalGuardException;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStage;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflow;
use ByRcsc\LaravelApproval\Support\ApprovalGuard;
use ByRcsc\LaravelApproval\Support\Attributes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * Makes a model submittable to an approval workflow.
 *
 * The trait is limited to model-centric members: the approval relationships,
 * submission, access to the model's current request, reporting conveniences for
 * the current stage, and model scopes.
 *
 * Commands have exactly one path, the `Approval` facade. The trait mirrors none
 * of them, with one deliberate exception: {@see withoutApprovalGuard()}, because
 * guard mode is itself a model-level feature.
 *
 * Eligibility is the other deliberate duplication. `Approval::eligibility()`
 * takes a request, {@see ApprovalRequest::approvalEligibility()} asks it of a
 * request you already hold, and {@see approvalEligibility()} here asks it of this
 * model's current request, returning null when there is none. Withdrawal and
 * delegation eligibility are request-level questions only, so ask those on the
 * request.
 *
 * Submission is explicit. Guard mode can additionally intercept Eloquent saves
 * to attributes held in a pending draft; explicit mode, the default, intercepts
 * nothing. Query-builder and raw writes stay outside model events, and the
 * stale check performed before a draft is applied is the backstop for them.
 *
 * The `approval*` and `approvable*` methods are configuration seams. Override
 * them on the model to declare which workflow it uses, which attributes may be
 * drafted, and who submits.
 *
 * @phpstan-require-extends Model
 */
trait Approvable
{
    public static function bootApprovable(): void
    {
        static::saving(function (Model $model): void {
            if (! $model->exists || ApprovalGuard::bypassing($model)) {
                return;
            }

            if (! method_exists($model, 'approvalMode') || $model->approvalMode() !== ApprovalMode::Guard) {
                return;
            }

            /** @var ApprovalRequest|null $request */
            $request = Approval::pendingFor($model);

            if ($request === null) {
                return;
            }

            $drafted = array_keys(Attributes::object($request, 'new_attributes'));
            $guarded = array_values(array_intersect($drafted, array_keys($model->getDirty())));

            if ($guarded !== []) {
                throw ApprovalGuardException::draftedAttributes($model, $request, $guarded);
            }
        });

        static::deleted(function (self $model): void {
            $usesSoftDeletes = in_array(SoftDeletes::class, class_uses_recursive($model), true);
            $forceDeleting = $usesSoftDeletes
                && method_exists($model, 'isForceDeleting')
                && $model->isForceDeleting();

            if ($usesSoftDeletes && ! $forceDeleting && ! $model->closeApprovalOnSoftDelete()) {
                return;
            }

            Approval::requestsFor($model)
                ->unresolved()
                ->each(fn (ApprovalRequest $request): ApprovalRequest => app(SystemCloseRequest::class)(
                    $request,
                    'The approvable model was deleted.',
                ));
        });

        static::created(function (self $model): void {
            $enabled = $model instanceof AutoSubmitsForApproval || $model->shouldAutoSubmitForApproval();

            if (! $enabled || Approval::pendingFor($model) !== null) {
                return;
            }

            $submission = $model->approvalAutoSubmission();

            Approval::submit(
                $model,
                $submission->workflow,
                $submission->newAttributes,
                $submission->requester,
                $submission->comment,
                $submission->metadata,
            );
        });
    }

    /**
     * Every request for this model, newest first, including closed ones. Closed
     * requests are retained as approval history.
     *
     * @return MorphMany<ApprovalRequest, $this>
     */
    public function approvalRequests(): MorphMany
    {
        return $this->morphMany(ApprovalRequest::class, 'approvable')->orderByDesc('id');
    }

    /**
     * @return MorphOne<ApprovalRequest, $this>
     */
    public function latestApprovalRequest(): MorphOne
    {
        return $this->morphOne(ApprovalRequest::class, 'approvable')->latestOfMany();
    }

    /**
     * Submit this already-persisted record for approval.
     *
     * Passing `$newAttributes` holds those proposed values in a draft until the
     * request is approved. Passing null creates a record-level request: the
     * record itself is what needs approval, and the package applies no
     * attribute changes when the request completes.
     *
     * @param  array<string, mixed>|null  $newAttributes  null requests approval of the record itself rather than of an edit to it
     * @param  array<string, mixed>  $metadata
     */
    public function submitForApproval(
        ApprovalWorkflow|string|null $workflow = null,
        ?array $newAttributes = null,
        ?Model $requester = null,
        ?string $comment = null,
        array $metadata = [],
    ): ApprovalRequest {
        return Approval::submit($this, $workflow, $newAttributes, $requester, $comment, $metadata);
    }

    /**
     * This model's unresolved request - pending or conflicted - of which there
     * is at most one.
     */
    public function pendingApprovalRequest(): ?ApprovalRequest
    {
        return Approval::pendingFor($this);
    }

    public function isPendingApproval(): bool
    {
        return $this->pendingApprovalRequest() !== null;
    }

    /**
     * Whether this user may approve the unresolved request right now.
     *
     * False covers both "not this user's stage" and "no unresolved request";
     * {@see approvalEligibility()} distinguishes them.
     */
    public function canBeApprovedBy(Model $user): bool
    {
        return $this->approvalEligibility($user)?->eligible === true;
    }

    /**
     * Why this user may or may not decide the unresolved request, or null when
     * there is no unresolved request. Withdrawal and delegation eligibility are
     * request-level questions; ask them on the request itself.
     */
    public function approvalEligibility(Model $user): ?Eligibility
    {
        $request = $this->pendingApprovalRequest();

        return $request === null ? null : Approval::eligibility($request, $user);
    }

    public function currentApprovalStage(): ?ApprovalRequestStage
    {
        return $this->pendingApprovalRequest()?->currentStage();
    }

    /**
     * The approvers the open request's current stage is still waiting on.
     * Holders who have already responded are excluded;
     * {@see currentStageApprovers()} lists every holder.
     *
     * @return Collection<int, Model>
     */
    public function pendingApprovalApprovers(): Collection
    {
        return $this->pendingApprovalRequest()?->pendingApprovers() ?? collect();
    }

    /**
     * Every assignment holder for the open request's current stage, whether or
     * not they have already responded.
     *
     * @return Collection<int, Model>
     */
    public function currentStageApprovers(): Collection
    {
        return $this->pendingApprovalRequest()?->currentStageApprovers() ?? collect();
    }

    /**
     * The timeline of the latest request only. Earlier requests in a linked
     * chain keep their own timelines, reachable through each request's
     * `resubmittedFrom()` relation.
     *
     * @return Collection<int, TimelineEntry>
     */
    public function latestApprovalTimeline(): Collection
    {
        return $this->latestApprovalRequest()->first()?->timeline() ?? collect();
    }

    /**
     * The draft waiting to be applied, or null when there is none. Null covers
     * both "no unresolved request" and "the unresolved request is record-level";
     * use {@see pendingApprovalRequest()} to distinguish them.
     */
    public function pendingDraft(): ?Draft
    {
        $request = $this->pendingApprovalRequest();

        return $request === null ? null : Draft::fromRequest($request);
    }

    /**
     * Operational escape hatch: run a correction on this model while guard mode
     * holds a pending draft. The bypass is appended to the open request's audit
     * trail. Equivalent to `Approval::withoutGuard($model, ...)`.
     *
     * @template TValue
     *
     * @param  callable($this): TValue  $callback
     * @return TValue
     */
    public function withoutApprovalGuard(
        callable $callback,
        ?Model $actor = null,
        ?string $comment = null,
    ): mixed {
        return Approval::withoutGuard($this, $callback, $actor, $comment);
    }

    /**
     * Which workflow this model goes through. Return a slug or a workflow;
     * returning null falls through to the `approval.workflows` config map.
     */
    public function approvalWorkflow(): ApprovalWorkflow|string|null
    {
        return null;
    }

    /**
     * Explicit mode is the default. Configure
     * `approval.models.<class>.mode` as `guard`, or override this method.
     */
    public function approvalMode(): ApprovalMode
    {
        /** @var mixed $models */
        $models = config('approval.models');
        $models = is_array($models) ? $models : [];
        $settings = $models[$this::class] ?? $models[$this->getMorphClass()] ?? [];
        $settings = is_array($settings) ? $settings : [];
        $mode = $settings['mode'] ?? ApprovalMode::Explicit->value;

        return $mode instanceof ApprovalMode
            ? $mode
            : ApprovalMode::tryFrom(is_string($mode) ? $mode : '') ?? ApprovalMode::Explicit;
    }

    /**
     * Soft-deleted records are recoverable by default, so their request stays
     * recoverable too. Hard deletes and force deletes always close it.
     */
    public function closeApprovalOnSoftDelete(): bool
    {
        return false;
    }

    /**
     * Whether creating this model should also create an approval request. The
     * request is created after the record is persisted; it does not gate
     * creation. Implementing {@see AutoSubmitsForApproval} has the same effect.
     */
    public function shouldAutoSubmitForApproval(): bool
    {
        return false;
    }

    /**
     * The workflow, requester, comment, and draft used by automatic submission.
     */
    public function approvalAutoSubmission(): AutoSubmission
    {
        return new AutoSubmission(
            workflow: $this->approvalWorkflow(),
            requester: $this->approvalRequester(),
        );
    }

    /**
     * Models whose latest request is in one of the given states. This is the
     * current-state question; {@see scopeEverInApprovalState()} searches the
     * whole request history.
     *
     * @param  Builder<static>  $query
     */
    public function scopeApprovalState(Builder $query, RequestState|string ...$states): void
    {
        $query->whereHas(
            'latestApprovalRequest',
            fn (Builder $request) => $request->whereIn('state', self::approvalStateValues($states)),
        );
    }

    /**
     * Models that have ever had a request in one of the given states, however
     * long ago and whatever came after it.
     *
     * @param  Builder<static>  $query
     */
    public function scopeEverInApprovalState(Builder $query, RequestState|string ...$states): void
    {
        $query->whereHas(
            'approvalRequests',
            fn (Builder $request) => $request->whereIn('state', self::approvalStateValues($states)),
        );
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopePendingApproval(Builder $query): void
    {
        $query->approvalState(RequestState::Pending);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeApprovalConflicted(Builder $query): void
    {
        $query->approvalState(RequestState::Conflicted);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeApprovalApproved(Builder $query): void
    {
        $query->approvalState(RequestState::Approved);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeApprovalRejected(Builder $query): void
    {
        $query->approvalState(RequestState::Rejected);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeApprovalReturned(Builder $query): void
    {
        $query->approvalState(RequestState::Returned);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeApprovalWithdrawn(Builder $query): void
    {
        $query->approvalState(RequestState::Withdrawn);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeApprovalCancelled(Builder $query): void
    {
        $query->approvalState(RequestState::Cancelled);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeApprovalSuperseded(Builder $query): void
    {
        $query->approvalState(RequestState::Superseded);
    }

    /**
     * Models with no unresolved request - neither pending nor conflicted.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWithoutPendingApproval(Builder $query): void
    {
        $query->whereDoesntHave(
            'approvalRequests',
            fn (Builder $request) => $request->whereIn('state', [
                RequestState::Pending->value,
                RequestState::Conflicted->value,
            ]),
        );
    }

    /**
     * @param  array<array-key, RequestState|string>  $states
     * @return list<string>
     */
    private static function approvalStateValues(array $states): array
    {
        return array_values(array_map(
            fn (RequestState|string $state): string => $state instanceof RequestState ? $state->value : $state,
            $states,
        ));
    }

    /**
     * Models whose unresolved request has an active stage waiting on this user.
     *
     * @param  Builder<static>  $query
     */
    public function scopeAwaitingApprovalBy(Builder $query, Model $user): void
    {
        $model = $query->getModel();

        $query->whereIn(
            $model->qualifyColumn($model->getKeyName()),
            ApprovalRequest::query()
                ->awaitingUser($user)
                ->where('approvable_type', $model->getMorphClass())
                ->select('approvable_id'),
        );
    }

    /**
     * Which attributes may be drafted. Null - the default - allows all of them.
     * Return an explicit list on models with columns the approval workflow
     * should not be able to change, such as a status column owned by another
     * part of the application.
     *
     * @return list<string>|null
     */
    public function approvableAttributes(): ?array
    {
        return null;
    }

    /**
     * Who is submitting, when it is not the authenticated user. Return null to
     * fall through to the authenticated user.
     */
    public function approvalRequester(): ?Model
    {
        return null;
    }
}
