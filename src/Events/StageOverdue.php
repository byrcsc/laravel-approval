<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Events;

use ByRcsc\LaravelApproval\Enums\EscalationAction;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStage;

/**
 * A stage's deadline passed with nobody deciding.
 *
 * Fired once when the stage is first escalated and again at its configured
 * reminder cadence. The first event fires before the strategy acts, so a
 * listener sees the state that went overdue. Reminder events never rerun the
 * strategy.
 *
 * This is the event the default `notify` policy is built around: it does
 * nothing else, because being told is the whole point of it.
 */
final class StageOverdue extends ApprovalEvent
{
    public function __construct(
        ApprovalRequest $request,
        public readonly ApprovalRequestStage $stage,
        public readonly EscalationAction $action,
    ) {
        parent::__construct($request);
    }
}
