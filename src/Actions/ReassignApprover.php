<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Data\StageHealth;
use ByRcsc\LaravelApproval\Data\StageProgress;
use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Enums\ApproverSource;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Enums\StageStatus;
use ByRcsc\LaravelApproval\Events\ApproverReassigned;
use ByRcsc\LaravelApproval\Exceptions\AlreadyDecidedException;
use ByRcsc\LaravelApproval\Exceptions\DelegationException;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestAssignment;
use ByRcsc\LaravelApproval\Support\Attributes;
use ByRcsc\LaravelApproval\Support\DelegationsTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * An administrator rewrites which approver an assignment belongs to.
 *
 * The administrative counterpart to {@see DelegateApproval}. Where delegation
 * keeps the original approver on the assignment, reassignment replaces them, and
 * any delegation on the assignment is cleared with them.
 *
 * Works on any undecided stage rather than only the active one, because an
 * approver leaving also affects stages the request has not reached yet.
 *
 * An assignment that already carries a decision is not reassigned: moving it
 * would attribute that decision to an approver who never made it.
 */
final class ReassignApprover
{
    public function __construct(private readonly RecordAction $recordAction) {}

    public function __invoke(
        ApprovalRequest $request,
        ApprovalRequestAssignment $assignment,
        Model $to,
        Model $actor,
        ?string $comment = null,
    ): ApprovalRequestAssignment {
        return DB::transaction(function () use ($request, $assignment, $to, $actor, $comment): ApprovalRequestAssignment {
            $request->newQuery()->whereKey($request->getKey())->lockForUpdate()->get();
            $request->refresh();
            $assignment->refresh();

            $state = Attributes::enum($request, 'state', RequestState::class) ?? RequestState::Pending;

            if ($state !== RequestState::Pending) {
                throw AlreadyDecidedException::requestIsClosed($request, $state);
            }

            $stage = $assignment->stage;

            if ($stage === null || ! $request->is($stage->request)) {
                throw AlreadyDecidedException::assignmentIsNotOnRequest($request);
            }

            $stageState = Attributes::enum($stage, 'state', StageStatus::class) ?? StageStatus::Queued;

            if (! in_array($stageState, [StageStatus::Active, StageStatus::Queued], true)) {
                throw AlreadyDecidedException::stageIsClosed($request);
            }

            $progress = StageProgress::for($stage);

            if ($progress->hasDecision($assignment)) {
                throw AlreadyDecidedException::assignmentHasDecision($request);
            }

            if ($progress->assignmentFor($to) !== null) {
                throw DelegationException::alreadyAssigned($to);
            }

            // An assignment handed to somebody who has themselves gone is the problem
            // this action exists to solve, not a solution to it.
            if (! $to->exists) {
                throw DelegationException::doesNotExist($to);
            }

            $from = $assignment->approver;
            $health = StageHealth::inspect($stage);

            $assignment->forceFill([
                'approver_type' => $to->getMorphClass(),
                'approver_id' => $to->getKey(),
                'delegated_to_type' => null,
                'delegated_to_id' => null,
                ...(DelegationsTable::installed() ? ['delegation_id' => null] : []),
                'source' => ApproverSource::Reassigned,
            ])->save();

            StageHealth::assertNotWorsened($stage, $request->workflowSlug(), $health);

            ($this->recordAction)(
                request: $request,
                action: ActionType::Reassigned,
                actor: $actor,
                stage: $stage,
                comment: $comment,
                metadata: array_filter([
                    'from' => $from === null ? null : $from->getMorphClass().':'.$this->key($from),
                    'to' => $to->getMorphClass().':'.$this->key($to),
                ]),
            );

            Event::dispatch(new ApproverReassigned($request, $assignment, $from, $to, $actor));

            return $assignment;
        });
    }

    private function key(Model $model): string
    {
        $key = $model->getKey();

        return is_scalar($key) ? (string) $key : '';
    }
}
