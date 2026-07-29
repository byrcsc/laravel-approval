<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Enums\StepState;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStep;
use ByRcsc\LaravelApproval\Support\Attributes;
use Illuminate\Support\Facades\Date;

/**
 * Makes a queued step the one people are waiting on.
 *
 * The SLA clock starts here rather than at submit: a level that spends two days
 * behind another level has not been slow, and dating its deadline from the
 * submission would say it had.
 */
final class ActivateStep
{
    public function __invoke(ApprovalRequestStep $step): ApprovalRequestStep
    {
        $sla = Attributes::nullableInt($step, 'sla_minutes');

        $step->forceFill([
            'state' => StepState::Active,
            'deadline_at' => $sla !== null && $sla > 0 ? Date::now()->addMinutes($sla) : null,
        ])->save();

        return $step;
    }
}
