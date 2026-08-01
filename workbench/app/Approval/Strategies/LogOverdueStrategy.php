<?php

declare(strict_types=1);

namespace Workbench\App\Approval\Strategies;

use ByRcsc\LaravelApproval\Contracts\EscalationStrategy;
use ByRcsc\LaravelApproval\Enums\EscalationAction;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStage;
use Illuminate\Support\Facades\Log;

/**
 * The demo's replacement for the shipped `record_only` strategy, and the reason
 * it exists is to show that replacing one is a config entry rather than a fork:
 *
 *     'approval.escalation.strategies.record_only' => LogOverdueStrategy::class,
 *
 * The shipped `RecordOnlyStrategy` is deliberately empty. By the time a strategy
 * runs, the stage is already marked escalated, `StageOverdue` has fired, and the
 * audit line is written - a strategy only supplies what differs. This one writes
 * a warning to the log, which in the demo is the same file the mail driver
 * writes to, so `approval:escalate` has something visible to show for itself.
 *
 * Two things a real strategy has to respect are visible here. It runs inside the
 * transaction covering that stage, so throwing rolls the stage's escalation mark
 * back and the next sweep retries it. And it must be idempotent in effect: the
 * sweep is safe to run every minute or every hour, and the stage only reaches a
 * strategy once per escalation, but a reminder cadence can bring it back.
 */
final class LogOverdueStrategy implements EscalationStrategy
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function handle(
        ApprovalRequest $request,
        ApprovalRequestStage $stage,
        EscalationAction $action,
        array $config,
    ): void {
        Log::warning('An approval stage passed its deadline.', [
            'request' => $request->getAttributeValue('ulid'),
            'workflow' => $request->workflowSlug(),
            'stage' => $stage->getAttributeValue('name'),
            'deadline_at' => (string) $stage->getAttributeValue('deadline_at'),
            'waiting_on' => $request->pendingApprovers()
                ->map(fn ($approver): mixed => $approver->getAttributeValue('name'))
                ->all(),
        ]);
    }
}
