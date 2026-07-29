<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Enums\StepState;
use ByRcsc\LaravelApproval\Events\ApprovalRejected;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStep;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;

/**
 * Closes a request as rejected, from whichever direction the "no" came.
 *
 * The counterpart to {@see CarryStep}: a person rejecting and a deadline
 * expiring on an auto-reject level end the same way, and they end it here so
 * there is only one description of what "rejected" does to a request.
 *
 * Levels that never ran are `skipped`. They did not decide anything, and
 * recording that they had would be a lie the reports would repeat.
 *
 * Expects the caller to hold the request's row lock and to have already
 * recorded the audit action that explains why.
 */
final class FailRequest
{
    public function __invoke(
        ApprovalRequest $request,
        ApprovalRequestStep $step,
        ?Model $actor,
        string $comment,
    ): void {
        $step->forceFill(['state' => StepState::Rejected])->save();

        $request->steps()
            ->whereKeyNot($step->getKey())
            ->whereIn('state', [StepState::Queued->value, StepState::Active->value])
            ->update(['state' => StepState::Skipped->value]);

        $request->forceFill([
            'state' => RequestState::Rejected,
            'completed_at' => Date::now(),
        ])->save();

        Event::dispatch(new ApprovalRejected($request, $step, $actor, $comment));
    }
}
