<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Escalation;

use ByRcsc\LaravelApproval\Actions\CarryStep;
use ByRcsc\LaravelApproval\Actions\FailRequest;
use ByRcsc\LaravelApproval\Actions\RecordAction;
use ByRcsc\LaravelApproval\Contracts\EscalationStrategy;
use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Enums\EscalationAction;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStep;
use ByRcsc\LaravelApproval\Support\Payload;

/**
 * Decides the level itself when the deadline passes.
 *
 * Serves both `auto_approve` and `auto_reject`; which one it is comes in as the
 * action rather than as two near-identical classes.
 *
 * The audit trail records these with no actor, because there was none. That is
 * the honest entry: "the deadline passed and the level was approved" is a
 * different fact from "somebody approved it", and an audit that could not tell
 * them apart would be worth less than no audit.
 *
 * `auto_approve` carries the level and lets the request move on exactly as a
 * human approval would, applying the draft if it was the last level - the same
 * stale check and all. `auto_reject` closes the request; the submitter can
 * resubmit.
 */
final class AutoDecideStrategy implements EscalationStrategy
{
    public function __construct(
        private readonly RecordAction $recordAction,
        private readonly CarryStep $carryStep,
        private readonly FailRequest $failRequest,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public function handle(
        ApprovalRequest $request,
        ApprovalRequestStep $step,
        EscalationAction $action,
        array $config,
    ): void {
        $comment = Payload::nullableString($config, 'comment');

        match ($action) {
            EscalationAction::AutoApprove => $this->approve($request, $step, $comment),
            EscalationAction::AutoReject => $this->reject($request, $step, $comment),
            // Reaching here means the config points a notify or escalate level
            // at this class. Nothing sensible to do, so nothing is done.
            default => null,
        };
    }

    private function approve(ApprovalRequest $request, ApprovalRequestStep $step, ?string $comment): void
    {
        ($this->recordAction)(
            request: $request,
            action: ActionType::Approved,
            step: $step,
            comment: $comment ?? 'Approved automatically: the level passed its deadline.',
            metadata: ['automatic' => true],
        );

        ($this->carryStep)($request, $step);
    }

    private function reject(ApprovalRequest $request, ApprovalRequestStep $step, ?string $comment): void
    {
        $comment ??= 'Rejected automatically: the level passed its deadline.';

        ($this->recordAction)(
            request: $request,
            action: ActionType::Rejected,
            step: $step,
            comment: $comment,
            metadata: ['automatic' => true],
        );

        ($this->failRequest)($request, $step, null, $comment);
    }
}
