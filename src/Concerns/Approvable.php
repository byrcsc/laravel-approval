<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Concerns;

use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflow;
use ByRcsc\LaravelApproval\Support\Attributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Makes a model something that can be put through a workflow.
 *
 * Nothing here intercepts `save()`. A record only enters a workflow because
 * something called `submitForApproval()`, which means the trait cannot silently
 * change what existing code does - and equally, that code which bypasses it
 * bypasses the engine. That is the trade v1 makes, and enforce mode is where
 * the other half of it lives.
 *
 * The three `approval*` methods below are the seams. Override them on the model
 * rather than configuring them from outside: which workflow a purchase order
 * uses is a fact about purchase orders.
 *
 * @phpstan-require-extends Model
 */
trait Approvable
{
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
     */
    public function submitForApproval(
        ApprovalWorkflow|string|null $workflow = null,
        ?array $newAttributes = null,
        ?Model $submitter = null,
    ): ApprovalRequest {
        return Approval::submit($this, $workflow, $newAttributes, $submitter);
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
     * Which workflow this model goes through. Return a slug or a workflow;
     * returning null falls through to the `approval.workflows` config map.
     */
    public function approvalWorkflow(): ApprovalWorkflow|string|null
    {
        return null;
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
