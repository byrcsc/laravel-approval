<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Data\CancellationEligibility;
use ByRcsc\LaravelApproval\Enums\CancellationIneligibility;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Support\Attributes;
use Illuminate\Database\Eloquent\Model;

final class CheckCancellationEligibility
{
    public function __invoke(ApprovalRequest $request, Model $actor): CancellationEligibility
    {
        $state = Attributes::enum($request, 'state', RequestState::class) ?? RequestState::Pending;

        if ($state !== RequestState::Pending) {
            return CancellationEligibility::denied(CancellationIneligibility::RequestClosed);
        }

        if (! $request->wasSubmittedBy($actor)) {
            return CancellationEligibility::denied(CancellationIneligibility::NotSubmitter);
        }

        return CancellationEligibility::granted();
    }
}
