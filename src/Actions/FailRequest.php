<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Enums\StageStatus;
use ByRcsc\LaravelApproval\Events\ApprovalRejected;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;

/**
 * Closes a request as rejected, whatever produced the rejection.
 *
 * The counterpart to {@see CompleteStage}. An approver's rejection and an
 * auto-reject escalation both end here, so there is one definition of what
 * rejection does to a request.
 *
 * Stages that never ran are recorded as `skipped`, because they did not decide
 * anything.
 *
 * Expects the caller to hold the request's row lock and to have already recorded
 * the action that explains the rejection.
 */
final class FailRequest
{
    public function __invoke(
        ApprovalRequest $request,
        ApprovalRequestStage $stage,
        ?Model $actor,
        string $comment,
    ): void {
        $stage->forceFill(['state' => StageStatus::Rejected])->save();

        $request->stages()
            ->whereKeyNot($stage->getKey())
            ->whereIn('state', [StageStatus::Queued->value, StageStatus::Active->value])
            ->update(['state' => StageStatus::Skipped->value]);

        $request->forceFill([
            'state' => RequestState::Rejected,
            'completed_at' => Date::now(),
        ])->save();

        Event::dispatch(new ApprovalRejected($request, $stage, $actor, $comment));
    }
}
