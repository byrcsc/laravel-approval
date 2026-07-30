<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Data\WithdrawalEligibility;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Enums\WithdrawalIneligibility;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Support\Attributes;
use Illuminate\Database\Eloquent\Model;

final class CheckWithdrawalEligibility
{
    public function __invoke(ApprovalRequest $request, Model $actor): WithdrawalEligibility
    {
        $state = Attributes::enum($request, 'state', RequestState::class) ?? RequestState::Pending;

        if ($state !== RequestState::Pending) {
            return WithdrawalEligibility::denied(WithdrawalIneligibility::RequestClosed);
        }

        if (! $request->wasSubmittedBy($actor)) {
            return WithdrawalEligibility::denied(WithdrawalIneligibility::NotRequester);
        }

        return WithdrawalEligibility::granted();
    }
}
