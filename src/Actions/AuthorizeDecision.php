<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Data\Ballot;
use ByRcsc\LaravelApproval\Data\DecisionContext;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Enums\StepState;
use ByRcsc\LaravelApproval\Exceptions\AlreadyDecidedException;
use ByRcsc\LaravelApproval\Exceptions\SelfApprovalException;
use ByRcsc\LaravelApproval\Exceptions\UnauthorizedApproverException;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStep;
use ByRcsc\LaravelApproval\Support\Attributes;
use ByRcsc\LaravelApproval\Support\Payload;
use Illuminate\Database\Eloquent\Model;

/**
 * The gate every decision passes through.
 *
 * The engine answers this itself rather than trusting the caller, because the
 * approver list is its own data and nothing outside it is in a position to
 * check. Host policies and gates layer on top; this is the floor.
 *
 * The four questions, in the order the answers are most useful: is the request
 * still open, is a level actually waiting, does this actor hold a seat on it,
 * and are they allowed to fill it.
 */
final class AuthorizeDecision
{
    public function __invoke(ApprovalRequest $request, Model $actor): DecisionContext
    {
        $state = Attributes::enum($request, 'state', RequestState::class) ?? RequestState::Pending;

        if ($state !== RequestState::Pending) {
            throw AlreadyDecidedException::requestIsClosed($request, $state);
        }

        $step = $this->activeStep($request);
        $ballot = Ballot::for($step);
        $seat = $ballot->seatFor($actor);

        if ($seat === null) {
            throw $ballot->hasDelegatedSeat($actor)
                ? UnauthorizedApproverException::slotIsDelegated($actor, $request)
                : UnauthorizedApproverException::notAnApprover($actor, $request);
        }

        if ($ballot->hasVoted($seat)) {
            throw AlreadyDecidedException::actorHasVoted($actor, $request);
        }

        if ($this->isSubmitter($request, $actor) && ! $this->allowsSelfApproval($request)) {
            throw SelfApprovalException::forSubmitter($actor, $request);
        }

        return new DecisionContext($step, $ballot, $seat);
    }

    private function activeStep(ApprovalRequest $request): ApprovalRequestStep
    {
        /** @var ApprovalRequestStep|null $step */
        $step = $request->steps()->where('state', StepState::Active->value)->first();

        return $step ?? throw AlreadyDecidedException::noActiveStep($request);
    }

    private function isSubmitter(ApprovalRequest $request, Model $actor): bool
    {
        return Attributes::nullableString($request, 'submitter_type') === $actor->getMorphClass()
            && Attributes::nullableString($request, 'submitter_id') === $this->stringKey($actor);
    }

    /**
     * Read live rather than snapshotted, unlike the level rules. Self-approval
     * is a control policy, not a rule about this request: an organization that
     * tightens it wants the tighter rule to apply to what is already in flight,
     * which is the opposite of what snapshotting would give them.
     */
    private function allowsSelfApproval(ApprovalRequest $request): bool
    {
        $workflow = $request->workflow;

        if ($workflow !== null) {
            $override = Payload::nullableBool(Attributes::object($workflow, 'settings'), 'allow_self_approval');

            if ($override !== null) {
                return $override;
            }
        }

        return (bool) config('approval.allow_self_approval', false);
    }

    private function stringKey(Model $model): string
    {
        $key = $model->getKey();

        return is_scalar($key) ? (string) $key : '';
    }
}
