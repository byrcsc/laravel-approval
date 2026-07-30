<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Escalation;

use ByRcsc\LaravelApproval\Actions\CompleteStage;
use ByRcsc\LaravelApproval\Actions\FailRequest;
use ByRcsc\LaravelApproval\Actions\RecordAction;
use ByRcsc\LaravelApproval\Contracts\EscalationStrategy;
use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Enums\EscalationAction;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStage;
use ByRcsc\LaravelApproval\Support\Payload;

/**
 * Decides the stage itself when the deadline passes.
 *
 * Serves both `auto_approve` and `auto_reject`; which one it is comes in as the
 * action rather than as two near-identical classes.
 *
 * The audit trail records these with no actor, because there was none. That is
 * the honest entry: "the deadline passed and the stage was approved" is a
 * different fact from "somebody approved it", and an audit that could not tell
 * them apart would be worth less than no audit.
 *
 * `auto_approve` completes the stage and lets the request move on exactly as a
 * human approval would, applying the draft if it was the last stage - the same
 * stale check and all. `auto_reject` closes the request; the submitter can
 * resubmit.
 */
final class AutoDecideStrategy implements EscalationStrategy
{
    public function __construct(
        private readonly RecordAction $recordAction,
        private readonly CompleteStage $completeStage,
        private readonly FailRequest $failRequest,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public function handle(
        ApprovalRequest $request,
        ApprovalRequestStage $stage,
        EscalationAction $action,
        array $config,
    ): void {
        $comment = Payload::nullableString($config, 'comment');

        match ($action) {
            EscalationAction::AutoApprove => $this->approve($request, $stage, $comment),
            EscalationAction::AutoReject => $this->reject($request, $stage, $comment),
            // Reaching here means the config points a record_only or escalate stage
            // at this class. Nothing sensible to do, so nothing is done.
            default => null,
        };
    }

    private function approve(ApprovalRequest $request, ApprovalRequestStage $stage, ?string $comment): void
    {
        ($this->recordAction)(
            request: $request,
            action: ActionType::Approved,
            stage: $stage,
            comment: $comment ?? 'Approved automatically: the stage passed its deadline.',
            metadata: ['automatic' => true],
        );

        ($this->completeStage)($request, $stage);
    }

    private function reject(ApprovalRequest $request, ApprovalRequestStage $stage, ?string $comment): void
    {
        $comment ??= 'Rejected automatically: the stage passed its deadline.';

        ($this->recordAction)(
            request: $request,
            action: ActionType::Rejected,
            stage: $stage,
            comment: $comment,
            metadata: ['automatic' => true],
        );

        ($this->failRequest)($request, $stage, null, $comment);
    }
}
