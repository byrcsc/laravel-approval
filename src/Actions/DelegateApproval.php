<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Data\DelegationEligibility;
use ByRcsc\LaravelApproval\Data\StageHealth;
use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Enums\ApproverSource;
use ByRcsc\LaravelApproval\Enums\DelegationIneligibility;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Events\ApprovalDelegated;
use ByRcsc\LaravelApproval\Exceptions\AlreadyDecidedException;
use ByRcsc\LaravelApproval\Exceptions\DelegationException;
use ByRcsc\LaravelApproval\Exceptions\UnauthorizedApproverException;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestAssignment;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStage;
use ByRcsc\LaravelApproval\Support\Attributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * "I am away this week - ask her instead."
 *
 * The assignment stays where it is and keeps the original approver's name on it;
 * only `delegated_to` changes. Who was asked is part of the record, and a
 * delegation that erased that would leave an audit trail that could not answer
 * the one question audits ask.
 *
 * From that moment the assignment is the delegate's alone. The original cannot decision
 * from it, and it leaves their inbox for the delegate's - one assignment, one holder,
 * which is what keeps the approval arithmetic honest.
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
    ): ApprovalRequestAssignment {
        return DB::transaction(function () use ($request, $approver, $delegate, $comment): ApprovalRequestAssignment {
            $request->newQuery()->whereKey($request->getKey())->lockForUpdate()->get();
            $request->refresh();

            $eligibility = ($this->checkEligibility)($request, $approver, $delegate);
            [$stage, $assignment] = $this->authorizedContext($request, $approver, $delegate, $eligibility);

            $health = StageHealth::inspect($stage);

            $assignment->forceFill([
                'delegated_to_type' => $delegate->getMorphClass(),
                'delegated_to_id' => $delegate->getKey(),
                'source' => ApproverSource::Delegated,
            ])->save();

            StageHealth::assertNotWorsened($stage, $request->workflowSlug(), $health);

            ($this->recordAction)(
                request: $request,
                action: ActionType::Delegated,
                actor: $approver,
                stage: $stage,
                comment: $comment,
                metadata: ['delegated_to' => $delegate->getMorphClass().':'.$this->key($delegate)],
            );

            Event::dispatch(new ApprovalDelegated($request, $assignment, $approver, $delegate, $comment));

            return $assignment;
        });
    }

    /**
     * @return array{ApprovalRequestStage, ApprovalRequestAssignment}
     */
    private function authorizedContext(
        ApprovalRequest $request,
        Model $approver,
        Model $delegate,
        DelegationEligibility $eligibility,
    ): array {
        if ($eligibility->eligible) {
            assert($eligibility->stage !== null);
            assert($eligibility->stageProgress !== null);
            assert($eligibility->assignment !== null);

            return [$eligibility->stage, $eligibility->assignment];
        }

        throw match ($eligibility->reason) {
            DelegationIneligibility::RequestClosed => AlreadyDecidedException::requestIsClosed(
                $request,
                Attributes::enum($request, 'state', RequestState::class) ?? RequestState::Pending,
            ),
            DelegationIneligibility::NoActiveStage => AlreadyDecidedException::noActiveStage($request),
            DelegationIneligibility::AssignmentDelegated => DelegationException::assignmentIsDelegated($approver),
            DelegationIneligibility::DecisionAlreadyRecorded => AlreadyDecidedException::decisionAlreadyRecorded(
                $approver,
                $request,
            ),
            DelegationIneligibility::ApproverMissing => DelegationException::doesNotExist($approver),
            DelegationIneligibility::CannotDelegateToSelf => DelegationException::toSelf($approver),
            DelegationIneligibility::DelegateAlreadyAssigned => DelegationException::alreadyAssigned($delegate),
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
