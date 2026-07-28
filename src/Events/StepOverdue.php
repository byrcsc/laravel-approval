<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Events;

use ByRcsc\LaravelApproval\Enums\EscalationAction;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStep;

/**
 * A level's deadline passed with nobody deciding.
 *
 * Fired once per step - the escalation mark makes sure of that, however often
 * the command runs - and fired before the strategy acts, so a listener sees the
 * request in the state that went overdue rather than in whatever the strategy
 * turned it into.
 *
 * This is the event the default `notify` policy is built around: it does
 * nothing else, because being told is the whole point of it.
 */
final class StepOverdue extends ApprovalEvent
{
    public function __construct(
        ApprovalRequest $request,
        public readonly ApprovalRequestStep $step,
        public readonly EscalationAction $action,
    ) {
        parent::__construct($request);
    }
}
