<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\ApprovalModels;
use ByRcsc\LaravelApproval\Data\StageProgress;
use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Events\ApprovalDelegated;
use ByRcsc\LaravelApproval\Events\DelegationEnded;
use ByRcsc\LaravelApproval\Models\ApprovalDelegation;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestAssignment;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStage;
use ByRcsc\LaravelApproval\Support\Attributes;
use ByRcsc\LaravelApproval\Support\DelegationsTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;

/**
 * Brings one active stage's assignments in line with the standing delegations
 * in force right now.
 *
 * An undecided assignment whose approver has an active delegation goes to the
 * delegate, the same way a hand delegation does: `delegated_to` is filled and
 * the approver stays on the row. An undecided assignment routed by a delegation
 * that has since ended or been revoked goes back to its approver. A decided
 * assignment never moves, and neither does one delegated by hand.
 *
 * Routing skips a delegate who requested the approval or already holds an
 * assignment on the stage. The assignment then stays with its approver, because
 * moving it would let one person decide twice or approve their own request.
 * Delegations do not chain: a delegate who is away themselves keeps what was
 * routed to them.
 *
 * Expects the caller to hold the request's row lock.
 */
final class ApplyDelegations
{
    public function __construct(private readonly RecordAction $recordAction) {}

    /**
     * @param  bool  $announce  false when the caller's own event already tells the new holders
     * @return int how many assignments moved
     */
    public function __invoke(ApprovalRequestStage $stage, bool $announce = true): int
    {
        $request = $stage->request;

        if (! $request instanceof ApprovalRequest || ! DelegationsTable::installed()) {
            return 0;
        }

        $moved = 0;

        foreach ($stage->assignments()->orderBy('id')->get() as $assignment) {
            // Read fresh each time: a move earlier in the loop changes who
            // already holds an assignment on this stage.
            $progress = StageProgress::for($stage->setRelation('assignments', $stage->assignments()->get()));

            if ($progress->hasDecision($assignment)) {
                continue;
            }

            $moved += $assignment->getAttributeValue('delegation_id') !== null
                ? $this->returnIfEnded($request, $stage, $assignment)
                : $this->routeIfDelegated($request, $stage, $assignment, $progress, $announce);
        }

        $stage->unsetRelation('assignments');

        return $moved;
    }

    private function returnIfEnded(ApprovalRequest $request, ApprovalRequestStage $stage, ApprovalRequestAssignment $assignment): int
    {
        $delegation = $assignment->delegation;

        if ($delegation instanceof ApprovalDelegation && $delegation->isActive()) {
            return 0;
        }

        $delegate = $assignment->delegatedTo;
        $delegationId = $assignment->getAttributeValue('delegation_id');

        $assignment->forceFill([
            'delegated_to_type' => null,
            'delegated_to_id' => null,
            'delegation_id' => null,
        ])->save();

        ($this->recordAction)(
            request: $request,
            action: ActionType::DelegationEnded,
            stage: $stage,
            metadata: array_filter([
                'delegation' => $delegationId,
                'returned_from' => $delegate === null ? null : $this->identity($delegate),
            ]),
        );

        Event::dispatch(new DelegationEnded($request, $assignment->unsetRelation('delegatedTo'), $assignment->approver, $delegate));

        return 1;
    }

    private function routeIfDelegated(
        ApprovalRequest $request,
        ApprovalRequestStage $stage,
        ApprovalRequestAssignment $assignment,
        StageProgress $progress,
        bool $announce,
    ): int {
        if ($assignment->getAttributeValue('delegated_to_id') !== null) {
            return 0;
        }

        $approver = $assignment->approver;

        if (! $approver instanceof Model) {
            return 0;
        }

        /** @var ApprovalDelegation|null $delegation */
        $delegation = ApprovalModels::delegation()::query()->active()->fromDelegator($approver)->latest('id')->first();
        $delegate = $delegation?->delegate;

        if ($delegation === null || ! $delegate instanceof Model) {
            return 0;
        }

        if ($request->wasRequestedBy($delegate) || $progress->assignmentFor($delegate) !== null) {
            return 0;
        }

        $assignment->forceFill([
            'delegated_to_type' => $delegate->getMorphClass(),
            'delegated_to_id' => $delegate->getKey(),
            'delegation_id' => $delegation->getKey(),
        ])->save();

        $comment = Attributes::nullableString($delegation, 'comment');

        ($this->recordAction)(
            request: $request,
            action: ActionType::Delegated,
            actor: $approver,
            stage: $stage,
            comment: $comment,
            metadata: [
                'delegated_to' => $this->identity($delegate),
                'delegation' => $delegation->getKey(),
            ],
        );

        if ($announce) {
            Event::dispatch(new ApprovalDelegated($request, $assignment, $approver, $delegate, $comment));
        }

        return 1;
    }

    private function identity(Model $model): string
    {
        $key = $model->getKey();

        return $model->getMorphClass().':'.(is_scalar($key) ? (string) $key : '');
    }
}
