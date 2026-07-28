<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Events\ApprovalResumed;
use ByRcsc\LaravelApproval\Exceptions\AlreadyDecidedException;
use ByRcsc\LaravelApproval\Exceptions\InvalidDraftException;
use ByRcsc\LaravelApproval\Exceptions\WorkflowNotFoundException;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflow;
use ByRcsc\LaravelApproval\Support\Attributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

final class ResumeReturnedRequest
{
    public function __construct(
        private readonly SubmitForApproval $submit,
        private readonly RecordAction $recordAction,
    ) {}

    /**
     * @param  array<string, mixed>|null  $changes
     */
    public function __invoke(
        ApprovalRequest $request,
        ?array $changes = null,
        ApprovalWorkflow|string|null $workflow = null,
    ): ApprovalRequest {
        return DB::transaction(function () use ($request, $changes, $workflow): ApprovalRequest {
            $request->refresh();
            $state = Attributes::enum($request, 'state', RequestState::class) ?? RequestState::Pending;

            if ($state !== RequestState::Returned) {
                throw AlreadyDecidedException::notReturned($request, $state);
            }

            $approvable = $request->approvable;
            $submitter = $request->submitter;

            if (! $approvable instanceof Model || ! $submitter instanceof Model) {
                throw AlreadyDecidedException::retryHasNoSubject($request);
            }

            $draft = $this->draft($request, $changes);
            $replacement = ($this->submit)(
                $approvable,
                $this->workflowFor($request, $workflow),
                $draft,
                $submitter,
            );

            $replacement->forceFill(['resubmitted_from_id' => $request->getKey()])->save();

            ($this->recordAction)(
                request: $replacement,
                action: ActionType::Resumed,
                actor: $submitter,
                metadata: ['returned_request' => Attributes::string($request, 'ulid')],
            );

            Event::dispatch(new ApprovalResumed($replacement, $request));

            return $replacement;
        });
    }

    /**
     * @param  array<string, mixed>|null  $changes
     * @return array<string, mixed>|null
     */
    private function draft(ApprovalRequest $request, ?array $changes): ?array
    {
        $original = $request->getAttributeValue('new_attributes') === null
            ? null
            : Attributes::object($request, 'new_attributes');

        if ($changes === null) {
            return $original;
        }

        $allowed = array_keys($original ?? []);
        $added = array_values(array_diff(array_keys($changes), $allowed));

        if ($added !== []) {
            throw InvalidDraftException::resumeAddsAttributes($added, $allowed);
        }

        return array_replace($original ?? [], $changes);
    }

    private function workflowFor(ApprovalRequest $request, ApprovalWorkflow|string|null $given): ApprovalWorkflow
    {
        if ($given instanceof ApprovalWorkflow) {
            return $given;
        }

        if (is_string($given) && $given !== '') {
            return ApprovalWorkflow::query()->where('slug', $given)->firstOrFail();
        }

        return $request->workflow ?? throw WorkflowNotFoundException::forRequest($request);
    }
}
