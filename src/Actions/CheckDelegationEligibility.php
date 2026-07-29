<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Data\DelegationEligibility;
use ByRcsc\LaravelApproval\Data\StageProgress;
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
            return DelegationEligibility::denied(DelegationIneligibility::NoActiveStage);
        }

        $progress = StageProgress::for($step);
        $assignment = $progress->assignmentFor($actor);

        if ($assignment === null) {
            return DelegationEligibility::denied(
                $progress->hasDelegatedAssignment($actor)
                    ? DelegationIneligibility::AssignmentDelegated
                    : DelegationIneligibility::NotAnApprover,
                $step,
                $progress,
            );
        }

        if ($progress->hasDecision($assignment)) {
            return DelegationEligibility::denied(
                DelegationIneligibility::DecisionAlreadyRecorded,
                $step,
                $progress,
            );
        }

        if (! $actor->exists) {
            return DelegationEligibility::denied(DelegationIneligibility::ApproverMissing, $step, $progress);
        }

        if ($delegate !== null && $progress->assignmentFor($delegate) !== null) {
            return DelegationEligibility::denied(
                DelegationIneligibility::DelegateAlreadyAssigned,
                $step,
                $progress,
            );
        }

        if ($delegate !== null && ! $delegate->exists) {
            return DelegationEligibility::denied(DelegationIneligibility::DelegateMissing, $step, $progress);
        }

        return DelegationEligibility::granted($step, $progress, $assignment);
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
