<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Contracts;

use ByRcsc\LaravelApproval\Enums\EscalationAction;
use ByRcsc\LaravelApproval\Escalation\RecordOnlyStrategy;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStage;

/**
 * What happens to a stage whose deadline has passed.
 *
 * By the time a strategy runs, the stage is already marked as escalated, the
 * `StageOverdue` event has been fired, and the audit line is written. Those are
 * the same whatever the policy is; a strategy only supplies the part that
 * differs. A strategy that does nothing is therefore a complete and sensible
 * one - see {@see RecordOnlyStrategy}.
 *
 * Which class handles which case is a config map, so replacing the shipped
 * behavior is a one-line change rather than a fork. Implementations are
 * resolved from the container, and run inside the transaction covering that
 * stage: throw, and nothing about the stage is committed, including the
 * escalation mark - so the next run tries again.
 */
interface EscalationStrategy
{
    /**
     * @param  EscalationAction  $action  which case sent it here, so one class can serve several
     * @param  array<string, mixed>  $config  the stage's snapshotted `escalation_config`
     */
    public function handle(
        ApprovalRequest $request,
        ApprovalRequestStage $stage,
        EscalationAction $action,
        array $config,
    ): void;
}
