<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Data\ApprovalDecision;
use ByRcsc\LaravelApproval\Data\StageProgress;
use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Enums\Verdict;
use ByRcsc\LaravelApproval\Exceptions\CommentRequiredException;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use Illuminate\Support\Facades\DB;

/**
 * Record a rejection and close the request only when its rejection policy says
 * the stage can no longer succeed.
 *
 * A rejection does not send the request back one stage. The request is closed,
 * and revising and resubmitting creates a new request that starts at the first
 * applicable stage against a fresh workflow snapshot. Stages that already
 * approved are therefore asked again.
 *
 * Stages that never ran are recorded as `skipped` rather than `rejected`,
 * because they did not decide anything.
 */
final class RejectRequest
{
    public function __construct(
        private readonly AuthorizeDecision $authorize,
        private readonly RecordAction $recordAction,
        private readonly FailRequest $failRequest,
    ) {}

    public function __invoke(ApprovalRequest $request, ApprovalDecision $decision): ApprovalRequest
    {
        $comment = $decision->comment();

        if ($decision->verdict !== Verdict::Reject || $comment === null) {
            throw CommentRequiredException::toReject();
        }

        return DB::transaction(function () use ($request, $decision, $comment): ApprovalRequest {
            $request->newQuery()->whereKey($request->getKey())->lockForUpdate()->get();
            $request->refresh();

            $context = ($this->authorize)($request, $decision->actor);

            ($this->recordAction)(
                request: $request,
                action: ActionType::Rejected,
                actor: $decision->actor,
                stage: $context->stage,
                comment: $comment,
                metadata: $decision->metadata,
            );

            $progress = StageProgress::for($context->stage);

            if ($progress->isRejected()) {
                ($this->failRequest)($request, $context->stage, $decision->actor, $comment);
            }

            return $request;
        });
    }
}
