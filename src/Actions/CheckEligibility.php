<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Data\Eligibility;
use ByRcsc\LaravelApproval\Data\StageProgress;
use ByRcsc\LaravelApproval\Enums\Ineligibility;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Support\Attributes;
use ByRcsc\LaravelApproval\Support\Payload;
use Illuminate\Database\Eloquent\Model;

/**
 * May this actor decide this request?
 *
 * The engine answers this itself rather than trusting the caller, because the
 * approver list is its own data and nothing outside it is in a position to
 * check. Host policies and gates layer on top; this is the floor.
 *
 * The four questions, in the order the answers are most useful: is the request
 * still open, is a stage actually waiting, does this actor hold an assignment on it,
 * and are they allowed to fill it.
 *
 * This is a read. It answers the same question for a disabled button as for a
 * decision about to be recorded - see `AuthorizeDecision` for the second, which is
 * this plus a throw.
 */
final class CheckEligibility
{
    public function __invoke(ApprovalRequest $request, Model $actor): Eligibility
    {
        $state = Attributes::enum($request, 'state', RequestState::class) ?? RequestState::Pending;

        if ($state !== RequestState::Pending) {
            return Eligibility::denied(Ineligibility::RequestClosed);
        }

        $stage = $request->currentStage();

        if ($stage === null) {
            return Eligibility::denied(Ineligibility::NoActiveStage);
        }

        $progress = StageProgress::for($stage);
        $assignment = $progress->assignmentFor($actor);

        if ($assignment === null) {
            return Eligibility::denied(
                $progress->hasDelegatedAssignment($actor)
                    ? Ineligibility::AssignmentDelegated
                    : Ineligibility::NotAnApprover,
                $stage,
                $progress,
            );
        }

        if ($progress->hasDecision($assignment)) {
            return Eligibility::denied(Ineligibility::DecisionAlreadyRecorded, $stage, $progress);
        }

        if ($this->isRequester($request, $actor) && ! $this->allowsSelfApproval($request)) {
            return Eligibility::denied(Ineligibility::SelfApproval, $stage, $progress);
        }

        return Eligibility::granted($stage, $progress, $assignment);
    }

    private function isRequester(ApprovalRequest $request, Model $actor): bool
    {
        return $request->wasRequestedBy($actor);
    }

    /**
     * Read live rather than snapshotted, unlike the stage rules. Self-approval
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
}
