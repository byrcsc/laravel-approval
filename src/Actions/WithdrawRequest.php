<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Enums\StageStatus;
use ByRcsc\LaravelApproval\Enums\WithdrawalIneligibility;
use ByRcsc\LaravelApproval\Events\ApprovalWithdrawn;
use ByRcsc\LaravelApproval\Exceptions\AlreadyDecidedException;
use ByRcsc\LaravelApproval\Exceptions\UnauthorizedApproverException;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Support\Attributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

final class WithdrawRequest
{
    public function __construct(
        private readonly RecordAction $recordAction,
        private readonly CheckWithdrawalEligibility $checkEligibility,
    ) {}

    public function __invoke(
        ApprovalRequest $request,
        Model $actor,
        ?string $comment = null,
    ): ApprovalRequest {
        return DB::transaction(function () use ($request, $actor, $comment): ApprovalRequest {
            $request->newQuery()->whereKey($request->getKey())->lockForUpdate()->get();
            $request->refresh();

            $state = Attributes::enum($request, 'state', RequestState::class) ?? RequestState::Pending;

            if ($state !== RequestState::Pending) {
                throw AlreadyDecidedException::requestIsClosed($request, $state);
            }

            $eligibility = ($this->checkEligibility)($request, $actor);

            if ($eligibility->reason === WithdrawalIneligibility::NotRequester) {
                throw UnauthorizedApproverException::notTheRequester($actor, $request);
            }

            $request->stages()
                ->whereIn('state', [StageStatus::Queued->value, StageStatus::Active->value])
                ->update(['state' => StageStatus::Skipped->value]);

            $request->forceFill([
                'state' => RequestState::Withdrawn,
                'completed_at' => Date::now(),
            ])->save();

            ($this->recordAction)(
                request: $request,
                action: ActionType::Withdrawn,
                actor: $actor,
                comment: $comment,
            );

            Event::dispatch(new ApprovalWithdrawn($request, $actor, $comment));

            return $request;
        });
    }
}
