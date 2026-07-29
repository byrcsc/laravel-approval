<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Exceptions\AlreadyDecidedException;
use ByRcsc\LaravelApproval\Exceptions\WorkflowNotFoundException;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflow;
use ByRcsc\LaravelApproval\Support\Attributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Another go, after a rejection or a withdrawal.
 *
 * A new request, from level one, against a fresh snapshot of the workflow. The
 * old one is not reopened and not edited - it is what happened, and the two are
 * joined by `resubmitted_from_id` rather than by one becoming the other.
 *
 * Starting again at level one is deliberate. The levels that approved the first
 * attempt approved that version of it; a resubmission is a different proposal,
 * and asking them again is the point rather than the cost.
 *
 * Everything unstated is inherited from the request being retried: its draft,
 * its workflow, its submitter. Pass any of them to change it.
 */
final class ResubmitRequest
{
    /**
     * The states a request can be retried from. `Approved` is missing on
     * purpose: that one succeeded, and wanting to change the record again is an
     * ordinary new submission rather than a retry of an old one.
     */
    private const RETRYABLE = [
        RequestState::Rejected,
        RequestState::Cancelled,
        RequestState::Conflicted,
    ];

    public function __construct(
        private readonly SubmitForApproval $submit,
        private readonly RecordAction $recordAction,
    ) {}

    /**
     * @param  array<string, mixed>|null  $newAttributes  omit to carry the original draft forward
     */
    public function __invoke(
        ApprovalRequest $request,
        ?array $newAttributes = null,
        ?Model $submitter = null,
        ApprovalWorkflow|string|null $workflow = null,
    ): ApprovalRequest {
        return DB::transaction(function () use ($request, $newAttributes, $submitter, $workflow): ApprovalRequest {
            $request->refresh();

            $state = Attributes::enum($request, 'state', RequestState::class) ?? RequestState::Pending;

            if (! in_array($state, self::RETRYABLE, true)) {
                throw AlreadyDecidedException::notRetryable($request, $state);
            }

            $approvable = $request->approvable;
            $original = $request->submitter;

            if (! $approvable instanceof Model || ! $original instanceof Model) {
                throw AlreadyDecidedException::retryHasNoSubject($request);
            }

            $replacement = ($this->submit)(
                $approvable,
                $this->workflowFor($request, $workflow),
                $newAttributes ?? $this->carriedDraft($request),
                $submitter ?? $original,
            );

            $replacement->forceFill(['resubmitted_from_id' => $request->getKey()])->save();

            ($this->recordAction)(
                request: $replacement,
                action: ActionType::Resubmitted,
                actor: $submitter ?? $original,
                metadata: ['resubmitted_from' => Attributes::string($request, 'ulid')],
            );

            return $replacement;
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function carriedDraft(ApprovalRequest $request): ?array
    {
        return $request->getAttributeValue('new_attributes') === null
            ? null
            : Attributes::object($request, 'new_attributes');
    }

    /**
     * Resolved fresh rather than snapshotted: a workflow edited in response to
     * the rejection is exactly what the retry should be judged by.
     */
    private function workflowFor(ApprovalRequest $request, ApprovalWorkflow|string|null $given): ApprovalWorkflow
    {
        if ($given instanceof ApprovalWorkflow) {
            return $given;
        }

        if (is_string($given) && $given !== '') {
            /** @var ApprovalWorkflow|null $workflow */
            $workflow = ApprovalWorkflow::query()->where('slug', $given)->first();

            return $workflow ?? throw WorkflowNotFoundException::forSlug($given);
        }

        return $request->workflow ?? throw WorkflowNotFoundException::forRequest($request);
    }
}
