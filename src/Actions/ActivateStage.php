<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Enums\StageStatus;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStage;
use ByRcsc\LaravelApproval\Support\Attributes;
use Illuminate\Support\Facades\Date;

/**
 * Makes a queued stage the one people are waiting on.
 *
 * The SLA clock starts here rather than at submit: a stage that spends two days
 * behind another stage has not been slow, and dating its deadline from the
 * submission would say it had.
 */
final class ActivateStage
{
    public function __invoke(ApprovalRequestStage $stage): ApprovalRequestStage
    {
        $sla = Attributes::nullableInt($stage, 'sla_minutes');

        $stage->forceFill([
            'state' => StageStatus::Active,
            'deadline_at' => $sla !== null && $sla > 0 ? Date::now()->addMinutes($sla) : null,
        ])->save();

        return $stage;
    }
}
