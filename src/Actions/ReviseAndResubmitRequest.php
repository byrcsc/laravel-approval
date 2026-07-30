<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Data\RevisedDraft;
use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Events\ApprovalResubmitted;
use ByRcsc\LaravelApproval\Exceptions\AlreadyDecidedException;
use ByRcsc\LaravelApproval\Exceptions\WorkflowNotFoundException;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflow;
use ByRcsc\LaravelApproval\Support\Attributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Another attempt, after a request closed without its change taking effect.
 *
 * A new request, from the first applicable stage, against a fresh snapshot of
 * the workflow. The old request is not reopened and not edited - it is what
 * happened, and the two are joined by `resubmitted_from_id` rather than by one
 * becoming the other.
 *
 * Starting again at the first stage is deliberate. The stages that approved
 * the first attempt approved that version of it; a revision is a different
 * proposal, and asking them again is the point rather than the cost.
 *
 * The draft is the caller's explicit choice ({@see RevisedDraft}); the
 * workflow and requester are inherited unless given.
 */
final class ReviseAndResubmitRequest
{
    /**
     * The states a request can be revised from. `Approved` is missing on
     * purpose: that one succeeded, and wanting to change the record again is an
     * ordinary new submission rather than a revision of an old one.
     */
    private const REVISABLE = [
        RequestState::Returned,
        RequestState::Rejected,
        RequestState::Withdrawn,
        RequestState::Cancelled,
        RequestState::Conflicted,
    ];

    public function __construct(
        private readonly SubmitForApproval $submit,
        private readonly RecordAction $recordAction,
    ) {}

    public function __invoke(
        ApprovalRequest $request,
        RevisedDraft $draft,
        ?Model $requester = null,
        ApprovalWorkflow|string|null $workflow = null,
    ): ApprovalRequest {
        return DB::transaction(function () use ($request, $draft, $requester, $workflow): ApprovalRequest {
            $request->newQuery()->whereKey($request->getKey())->lockForUpdate()->get();
            $request->refresh();

            $state = Attributes::enum($request, 'state', RequestState::class) ?? RequestState::Pending;

            if (! in_array($state, self::REVISABLE, true)) {
                throw AlreadyDecidedException::notRevisable($request, $state);
            }

            $approvable = $request->approvable;
            $original = $request->requester;

            if (! $approvable instanceof Model || ! $original instanceof Model) {
                throw AlreadyDecidedException::hasNoSubject($request);
            }

            $supersedingConflict = $state === RequestState::Conflicted;

            if ($supersedingConflict) {
                $request->forceFill([
                    'state' => RequestState::Superseded,
                    'completed_at' => Date::now(),
                ])->save();
            }

            $replacement = ($this->submit)(
                $approvable,
                $this->workflowFor($request, $workflow),
                $draft->resolve($this->previousDraft($request)),
                $requester ?? $original,
            );

            $replacement->forceFill(['resubmitted_from_id' => $request->getKey()])->save();

            ($this->recordAction)(
                request: $replacement,
                action: ActionType::Resubmitted,
                actor: $requester ?? $original,
                metadata: ['resubmitted_from' => Attributes::string($request, 'ulid')],
            );

            if ($supersedingConflict) {
                ($this->recordAction)(
                    request: $request,
                    action: ActionType::Superseded,
                    actor: $requester ?? $original,
                    metadata: ['replacement' => Attributes::string($replacement, 'ulid')],
                );
            }

            Event::dispatch(new ApprovalResubmitted($replacement, $request));

            return $replacement;
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function previousDraft(ApprovalRequest $request): ?array
    {
        return $request->getAttributeValue('new_attributes') === null
            ? null
            : Attributes::object($request, 'new_attributes');
    }

    /**
     * Resolved fresh rather than snapshotted: a workflow edited in response to
     * the rejection is exactly what the revision should be judged by.
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
