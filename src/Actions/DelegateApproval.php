<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Data\Ballot;
use ByRcsc\LaravelApproval\Data\StepHealth;
use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Enums\ApproverSource;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Enums\StepState;
use ByRcsc\LaravelApproval\Events\ApprovalDelegated;
use ByRcsc\LaravelApproval\Exceptions\AlreadyDecidedException;
use ByRcsc\LaravelApproval\Exceptions\DelegationException;
use ByRcsc\LaravelApproval\Exceptions\UnauthorizedApproverException;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStep;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStepApprover;
use ByRcsc\LaravelApproval\Support\Attributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * "I am away this week - ask her instead."
 *
 * The seat stays where it is and keeps the original approver's name on it;
 * only `delegated_to` changes. Who was asked is part of the record, and a
 * delegation that erased that would leave an audit trail that could not answer
 * the one question audits ask.
 *
 * From that moment the seat is the delegate's alone. The original cannot vote
 * from it, and it leaves their inbox for the delegate's - one seat, one holder,
 * which is what keeps quorum arithmetic honest.
 */
final class DelegateApproval
{
    public function __construct(private readonly RecordAction $recordAction) {}

    public function __invoke(
        ApprovalRequest $request,
        Model $approver,
        Model $delegate,
        ?string $comment = null,
    ): ApprovalRequestStepApprover {
        return DB::transaction(function () use ($request, $approver, $delegate, $comment): ApprovalRequestStepApprover {
            $request->newQuery()->whereKey($request->getKey())->lockForUpdate()->get();
            $request->refresh();

            $state = Attributes::enum($request, 'state', RequestState::class) ?? RequestState::Pending;

            if ($state !== RequestState::Pending) {
                throw AlreadyDecidedException::requestIsClosed($request, $state);
            }

            if ($approver->getMorphClass() === $delegate->getMorphClass() && $this->key($approver) === $this->key($delegate)) {
                throw DelegationException::toSelf($approver);
            }

            $step = $this->activeStep($request);
            $ballot = Ballot::for($step);
            $seat = $ballot->seatFor($approver);

            if ($seat === null) {
                throw $ballot->hasDelegatedSeat($approver)
                    ? DelegationException::seatIsTaken($approver)
                    : UnauthorizedApproverException::notAnApprover($approver, $request);
            }

            // Nothing to hand over once the seat has been used.
            if ($ballot->hasVoted($seat)) {
                throw AlreadyDecidedException::actorHasVoted($approver, $request);
            }

            // Two seats, one holder, and a quorum that can never be met.
            if ($ballot->seatFor($delegate) !== null) {
                throw DelegationException::alreadySeated($delegate);
            }

            $seat->forceFill([
                'delegated_to_type' => $delegate->getMorphClass(),
                'delegated_to_id' => $delegate->getKey(),
                'source' => ApproverSource::Delegated,
            ])->save();

            StepHealth::assertCarryable($step, $request->workflowName());

            ($this->recordAction)(
                request: $request,
                action: ActionType::Delegated,
                actor: $approver,
                step: $step,
                comment: $comment,
                metadata: ['delegated_to' => $delegate->getMorphClass().':'.$this->key($delegate)],
            );

            Event::dispatch(new ApprovalDelegated($request, $seat, $approver, $delegate, $comment));

            return $seat;
        });
    }

    private function activeStep(ApprovalRequest $request): ApprovalRequestStep
    {
        /** @var ApprovalRequestStep|null $step */
        $step = $request->steps()->where('state', StepState::Active->value)->first();

        return $step ?? throw AlreadyDecidedException::noActiveStep($request);
    }

    private function key(Model $model): string
    {
        $key = $model->getKey();

        return is_scalar($key) ? (string) $key : '';
    }
}
