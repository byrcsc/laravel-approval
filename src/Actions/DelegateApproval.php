<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Data\Ballot;
use ByRcsc\LaravelApproval\Data\DelegationEligibility;
use ByRcsc\LaravelApproval\Data\StepHealth;
use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Enums\ApproverSource;
use ByRcsc\LaravelApproval\Enums\DelegationIneligibility;
use ByRcsc\LaravelApproval\Enums\RequestState;
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
    public function __construct(
        private readonly RecordAction $recordAction,
        private readonly CheckDelegationEligibility $checkEligibility,
    ) {}

    public function __invoke(
        ApprovalRequest $request,
        Model $approver,
        Model $delegate,
        ?string $comment = null,
    ): ApprovalRequestStepApprover {
        return DB::transaction(function () use ($request, $approver, $delegate, $comment): ApprovalRequestStepApprover {
            $request->newQuery()->whereKey($request->getKey())->lockForUpdate()->get();
            $request->refresh();

            $eligibility = ($this->checkEligibility)($request, $approver, $delegate);
            [$step, $ballot, $seat] = $this->authorizedContext($request, $approver, $delegate, $eligibility);

            $health = StepHealth::inspect($step);

            $seat->forceFill([
                'delegated_to_type' => $delegate->getMorphClass(),
                'delegated_to_id' => $delegate->getKey(),
                'source' => ApproverSource::Delegated,
            ])->save();

            StepHealth::assertNotWorsened($step, $request->workflowName(), $health);

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

    /**
     * @return array{ApprovalRequestStep, Ballot, ApprovalRequestStepApprover}
     */
    private function authorizedContext(
        ApprovalRequest $request,
        Model $approver,
        Model $delegate,
        DelegationEligibility $eligibility,
    ): array {
        if ($eligibility->eligible) {
            assert($eligibility->step !== null);
            assert($eligibility->ballot !== null);
            assert($eligibility->seat !== null);

            return [$eligibility->step, $eligibility->ballot, $eligibility->seat];
        }

        throw match ($eligibility->reason) {
            DelegationIneligibility::RequestClosed => AlreadyDecidedException::requestIsClosed(
                $request,
                Attributes::enum($request, 'state', RequestState::class) ?? RequestState::Pending,
            ),
            DelegationIneligibility::NoActiveStep => AlreadyDecidedException::noActiveStep($request),
            DelegationIneligibility::SeatDelegated => DelegationException::seatIsTaken($approver),
            DelegationIneligibility::AlreadyVoted => AlreadyDecidedException::actorHasVoted($approver, $request),
            DelegationIneligibility::ApproverMissing => DelegationException::doesNotExist($approver),
            DelegationIneligibility::CannotDelegateToSelf => DelegationException::toSelf($approver),
            DelegationIneligibility::DelegateAlreadySeated => DelegationException::alreadySeated($delegate),
            DelegationIneligibility::DelegateMissing => DelegationException::doesNotExist($delegate),
            default => UnauthorizedApproverException::notAnApprover($approver, $request),
        };
    }

    private function key(Model $model): string
    {
        $key = $model->getKey();

        return is_scalar($key) ? (string) $key : '';
    }
}
