<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Enums\CancellationIneligibility;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Enums\StepState;
use ByRcsc\LaravelApproval\Events\ApprovalCancelled;
use ByRcsc\LaravelApproval\Exceptions\AlreadyDecidedException;
use ByRcsc\LaravelApproval\Exceptions\UnauthorizedApproverException;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Support\Attributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Withdrawing a request, which is not the same as deciding it.
 *
 * Only the submitter may do this, because withdrawing somebody else's request
 * is an administrative act rather than a participant's. `force` is how an admin
 * screen does it anyway - the audit trail records who, either way, and the host
 * app's own policy decides who gets to pass it.
 *
 * A null actor means the system did it, which is what an escalation strategy or
 * a cleanup command passes.
 */
final class CancelRequest
{
    public function __construct(
        private readonly RecordAction $recordAction,
        private readonly CheckCancellationEligibility $checkEligibility,
    ) {}

    public function __invoke(
        ApprovalRequest $request,
        ?Model $actor = null,
        ?string $comment = null,
        bool $force = false,
    ): ApprovalRequest {
        return DB::transaction(function () use ($request, $actor, $comment, $force): ApprovalRequest {
            $request->newQuery()->whereKey($request->getKey())->lockForUpdate()->get();
            $request->refresh();

            $state = Attributes::enum($request, 'state', RequestState::class) ?? RequestState::Pending;

            if ($state !== RequestState::Pending) {
                throw AlreadyDecidedException::requestIsClosed($request, $state);
            }

            if ($actor !== null && ! $force) {
                $eligibility = ($this->checkEligibility)($request, $actor);

                if ($eligibility->reason === CancellationIneligibility::NotSubmitter) {
                    throw UnauthorizedApproverException::notTheSubmitter($actor, $request);
                }
            }

            $request->steps()
                ->whereIn('state', [StepState::Queued->value, StepState::Active->value])
                ->update(['state' => StepState::Skipped->value]);

            $request->forceFill([
                'state' => RequestState::Cancelled,
                'completed_at' => Date::now(),
            ])->save();

            ($this->recordAction)(
                request: $request,
                action: ActionType::Cancelled,
                actor: $actor,
                comment: $comment,
            );

            Event::dispatch(new ApprovalCancelled($request, $actor, $comment));

            return $request;
        });
    }
}
