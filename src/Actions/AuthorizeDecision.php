<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Data\DecisionContext;
use ByRcsc\LaravelApproval\Enums\Ineligibility;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Exceptions\AlreadyDecidedException;
use ByRcsc\LaravelApproval\Exceptions\ApprovalException;
use ByRcsc\LaravelApproval\Exceptions\SelfApprovalException;
use ByRcsc\LaravelApproval\Exceptions\UnauthorizedApproverException;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Support\Attributes;
use Illuminate\Database\Eloquent\Model;

/**
 * The gate every decision passes through.
 *
 * `CheckEligibility` does the thinking; this turns a refusal into the exception
 * that says so. Keeping them one calculation is deliberate - a package where
 * the button and the decision disagreed about who may act would be worse than one
 * that never offered to answer the question.
 */
final class AuthorizeDecision
{
    public function __construct(private readonly CheckEligibility $check) {}

    public function __invoke(ApprovalRequest $request, Model $actor): DecisionContext
    {
        $eligibility = ($this->check)($request, $actor);

        return $eligibility->context() ?? throw $this->refusal($request, $actor, $eligibility->reason);
    }

    /**
     * The default arm is unreachable - a refusal always carries a reason - and
     * is written to deny anyway. An authorization check that cannot name its
     * objection should still be an objection.
     */
    private function refusal(ApprovalRequest $request, Model $actor, ?Ineligibility $reason): ApprovalException
    {
        return match ($reason) {
            Ineligibility::RequestClosed => AlreadyDecidedException::requestIsClosed($request, $this->state($request)),
            Ineligibility::NoActiveStage => AlreadyDecidedException::noActiveStage($request),
            Ineligibility::AssignmentDelegated => UnauthorizedApproverException::assignmentWasDelegated($actor, $request),
            Ineligibility::DecisionAlreadyRecorded => AlreadyDecidedException::decisionAlreadyRecorded($actor, $request),
            Ineligibility::SelfApproval => SelfApprovalException::forSubmitter($actor, $request),
            default => UnauthorizedApproverException::notAnApprover($actor, $request),
        };
    }

    private function state(ApprovalRequest $request): RequestState
    {
        return Attributes::enum($request, 'state', RequestState::class) ?? RequestState::Pending;
    }
}
