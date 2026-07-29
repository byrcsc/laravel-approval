<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Concerns;

use ByRcsc\LaravelApproval\Data\Eligibility;
use ByRcsc\LaravelApproval\Enums\ApprovalMode;
use ByRcsc\LaravelApproval\Exceptions\ApprovalGuardException;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflow;
use ByRcsc\LaravelApproval\Support\ApprovalGuard;
use ByRcsc\LaravelApproval\Support\Attributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Makes a model something that can be put through a workflow.
 *
 * Submission is explicit. Guard mode can additionally intercept Eloquent
 * saves to attributes held in a pending draft; explicit mode, the default,
 * intercepts nothing. Query-builder and raw writes remain outside model
 * events, with the final stale check as their backstop.
 *
 * The `approval*` methods below are the seams. Override them on the model
 * rather than configuring them from outside: which workflow a purchase order
 * uses is a fact about purchase orders.
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
    }

    /**
     * Newest first, including the closed ones - a rejected request is history,
     * not rubbish.
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
     * @param  array<string, mixed>|null  $newAttributes  null gates this record itself rather than an edit to it
     * @param  array<string, mixed>  $metadata
     */
    public function submitForApproval(
        ApprovalWorkflow|string|null $workflow = null,
        ?array $newAttributes = null,
        ?Model $submitter = null,
        ?string $comment = null,
        array $metadata = [],
    ): ApprovalRequest {
        return Approval::submit($this, $workflow, $newAttributes, $submitter, $comment, $metadata);
    }

    /**
     * The one request that is still awaiting a decision, of which there is at
     * most one.
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
     * Whether this user could approve the open request right now.
     *
     * The question a view asks before rendering the button. False covers both
     * "not their turn" and "nothing to approve" - `approvalEligibility()` is
     * the version that says which.
     */
    public function canBeApprovedBy(Model $user): bool
    {
        return $this->approvalEligibility($user)?->eligible === true;
    }

    /**
     * Why this user may or may not decide the open request, or null when there
     * is no open request to decide.
     */
    public function approvalEligibility(Model $user): ?Eligibility
    {
        $request = $this->pendingApprovalRequest();

        return $request === null ? null : Approval::eligibility($request, $user);
    }

    /**
     * The edit waiting to be applied, if there is one. Empty both when nothing
     * is pending and when what is pending is the record itself rather than a
     * change to it.
     *
     * @return array<string, mixed>
     */
    public function pendingDraft(): array
    {
        $request = $this->pendingApprovalRequest();

        return $request === null ? [] : Attributes::object($request, 'new_attributes');
    }

    /**
     * Withdraw the open request, if there is one. Returns false when there was
     * nothing to withdraw, so callers do not have to check first.
     */
    public function cancelApproval(?Model $actor = null, ?string $comment = null, bool $force = false): bool
    {
        $request = $this->pendingApprovalRequest();

        if ($request === null) {
            return false;
        }

        Approval::cancel($request, $actor, $comment, $force);

        return true;
    }

    /**
     * Run an exceptional correction while guard mode is active. The bypass is
     * always appended to the open request's audit trail.
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
     * Which attributes may be drafted. Null - the default - means all of them,
     * which is rarely what you want on a model with a `status` column somebody
     * else owns.
     *
     * @return list<string>|null
     */
    public function approvableAttributes(): ?array
    {
        return null;
    }

    /**
     * Who is submitting, when it is not simply the authenticated user. Return
     * null to fall through to that.
     */
    public function approvalSubmitter(): ?Model
    {
        return null;
    }
}
