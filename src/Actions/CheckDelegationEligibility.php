<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Data\Ballot;
use ByRcsc\LaravelApproval\Data\DelegationEligibility;
use ByRcsc\LaravelApproval\Enums\DelegationIneligibility;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Support\Attributes;
use Illuminate\Database\Eloquent\Model;

final class CheckDelegationEligibility
{
    public function __invoke(
        ApprovalRequest $request,
        Model $actor,
        ?Model $delegate = null,
    ): DelegationEligibility {
        $state = Attributes::enum($request, 'state', RequestState::class) ?? RequestState::Pending;

        if ($state !== RequestState::Pending) {
            return DelegationEligibility::denied(DelegationIneligibility::RequestClosed);
        }

        if ($delegate !== null && $this->same($actor, $delegate)) {
            return DelegationEligibility::denied(DelegationIneligibility::CannotDelegateToSelf);
        }

        $step = $request->currentStep();

        if ($step === null) {
            return DelegationEligibility::denied(DelegationIneligibility::NoActiveStep);
        }

        $ballot = Ballot::for($step);
        $seat = $ballot->seatFor($actor);

        if ($seat === null) {
            return DelegationEligibility::denied(
                $ballot->hasDelegatedSeat($actor)
                    ? DelegationIneligibility::SeatDelegated
                    : DelegationIneligibility::NotAnApprover,
                $step,
                $ballot,
            );
        }

        if ($ballot->hasVoted($seat)) {
            return DelegationEligibility::denied(DelegationIneligibility::AlreadyVoted, $step, $ballot);
        }

        if (! $actor->exists) {
            return DelegationEligibility::denied(DelegationIneligibility::ApproverMissing, $step, $ballot);
        }

        if ($delegate !== null && $ballot->seatFor($delegate) !== null) {
            return DelegationEligibility::denied(DelegationIneligibility::DelegateAlreadySeated, $step, $ballot);
        }

        if ($delegate !== null && ! $delegate->exists) {
            return DelegationEligibility::denied(DelegationIneligibility::DelegateMissing, $step, $ballot);
        }

        return DelegationEligibility::granted($step, $ballot, $seat);
    }

    private function same(Model $left, Model $right): bool
    {
        $leftKey = $left->getKey();
        $rightKey = $right->getKey();

        return $left->getMorphClass() === $right->getMorphClass()
            && is_scalar($leftKey)
            && is_scalar($rightKey)
            && (string) $leftKey === (string) $rightKey;
    }
}
