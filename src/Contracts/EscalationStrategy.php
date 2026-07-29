<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Contracts;

use ByRcsc\LaravelApproval\Enums\EscalationAction;
use ByRcsc\LaravelApproval\Escalation\NotifyStrategy;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStep;

/**
 * What happens to a level whose deadline has passed.
 *
 * By the time a strategy runs, the step is already marked as escalated, the
 * `StepOverdue` event has been fired, and the audit line is written. Those are
 * the same whatever the policy is; a strategy only supplies the part that
 * differs. A strategy that does nothing is therefore a complete and sensible
 * one - see {@see NotifyStrategy}.
 *
 * Which class handles which case is a config map, so replacing the shipped
 * behavior is a one-line change rather than a fork. Implementations are
 * resolved from the container, and run inside the transaction covering that
 * step: throw, and nothing about the step is committed, including the
 * escalation mark - so the next run tries again.
 */
interface EscalationStrategy
{
    /**
     * @param  EscalationAction  $action  which case sent it here, so one class can serve several
     * @param  array<string, mixed>  $config  the step's snapshotted `escalation_config`
     */
    public function handle(
        ApprovalRequest $request,
        ApprovalRequestStep $step,
        EscalationAction $action,
        array $config,
    ): void;
}
