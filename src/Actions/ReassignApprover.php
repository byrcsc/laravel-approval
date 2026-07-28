<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Data\Ballot;
use ByRcsc\LaravelApproval\Data\StepHealth;
use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Enums\ApproverSource;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Enums\StepState;
use ByRcsc\LaravelApproval\Events\ApproverReassigned;
use ByRcsc\LaravelApproval\Exceptions\AlreadyDecidedException;
use ByRcsc\LaravelApproval\Exceptions\DelegationException;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStepApprover;
use ByRcsc\LaravelApproval\Support\Attributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * "She left in March. Give it to him."
 *
 * The administrative counterpart to {@see DelegateApproval}: the seat is
 * rewritten rather than lent, because there is nobody to lend it. Any
 * delegation on the seat is cleared - it was the departed approver's to make,
 * and it dies with their claim to the seat.
 *
 * Works on any step that has not been decided, not only the active one, which
 * is the point: somebody leaving affects the levels their request has not
 * reached yet, and those are exactly the ones worth fixing before it does.
 *
 * A seat that has already voted is not reassigned. That vote was cast by a
 * person, and moving the seat under it would make the audit trail claim
 * somebody approved something they never saw.
 */
final class ReassignApprover
{
    public function __construct(private readonly RecordAction $recordAction) {}

    public function __invoke(
        ApprovalRequest $request,
        ApprovalRequestStepApprover $seat,
        Model $to,
        ?Model $actor = null,
        ?string $comment = null,
    ): ApprovalRequestStepApprover {
        return DB::transaction(function () use ($request, $seat, $to, $actor, $comment): ApprovalRequestStepApprover {
            $request->newQuery()->whereKey($request->getKey())->lockForUpdate()->get();
            $request->refresh();
            $seat->refresh();

            $state = Attributes::enum($request, 'state', RequestState::class) ?? RequestState::Pending;

            if ($state !== RequestState::Pending) {
                throw AlreadyDecidedException::requestIsClosed($request, $state);
            }

            $step = $seat->step;

            if ($step === null || ! $request->is($step->request)) {
                throw AlreadyDecidedException::seatIsNotOnRequest($request);
            }

            $stepState = Attributes::enum($step, 'state', StepState::class) ?? StepState::Queued;

            if (! in_array($stepState, [StepState::Active, StepState::Queued], true)) {
                throw AlreadyDecidedException::stepIsClosed($request);
            }

            $ballot = Ballot::for($step);

            if ($ballot->hasVoted($seat)) {
                throw AlreadyDecidedException::seatHasVoted($request);
            }

            if ($ballot->seatFor($to) !== null) {
                throw DelegationException::alreadySeated($to);
            }

            // A seat handed to somebody who has themselves gone is the problem
            // this action exists to solve, not a solution to it.
            if (! $to->exists) {
                throw DelegationException::doesNotExist($to);
            }

            $from = $seat->approver;
            $health = StepHealth::inspect($step);

            $seat->forceFill([
                'approver_type' => $to->getMorphClass(),
                'approver_id' => $to->getKey(),
                'delegated_to_type' => null,
                'delegated_to_id' => null,
                'source' => ApproverSource::Reassigned,
            ])->save();

            StepHealth::assertNotWorsened($step, $request->workflowName(), $health);

            ($this->recordAction)(
                request: $request,
                action: ActionType::Reassigned,
                actor: $actor,
                step: $step,
                comment: $comment,
                metadata: array_filter([
                    'from' => $from === null ? null : $from->getMorphClass().':'.$this->key($from),
                    'to' => $to->getMorphClass().':'.$this->key($to),
                ]),
            );

            Event::dispatch(new ApproverReassigned($request, $seat, $from, $to, $actor));

            return $seat;
        });
    }

    private function key(Model $model): string
    {
        $key = $model->getKey();

        return is_scalar($key) ? (string) $key : '';
    }
}
