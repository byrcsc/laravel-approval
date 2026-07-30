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
 * An approver transfers their own assignment to another approver.
 *
 * The assignment row is kept and retains the original approver's identity; only
 * `delegated_to` changes. The originally assigned approver stays in the record,
 * so the audit history shows who was asked as well as who answered.
 *
 * From that point the assignment belongs to the delegate alone: the original
 * approver can no longer decide it, and it moves from their inbox to the
 * delegate's. One assignment always has exactly one holder, which is what keeps
 * approvals received and approvals required consistent.
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
