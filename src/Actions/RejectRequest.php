<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Data\ApprovalDecision;
use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Enums\Verdict;
use ByRcsc\LaravelApproval\Exceptions\CommentRequiredException;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use Illuminate\Support\Facades\DB;

/**
 * One "no" closes the request.
 *
 * There is no sending it back a level for rework: the request becomes history
 * and resubmitting starts a fresh one at level one, against a fresh snapshot of
 * the workflow. That keeps a rejected request a thing that happened rather than
 * a thing that is still being negotiated - and it means the levels that already
 * approved get asked again, which is the point.
 *
 * Levels that never ran are `skipped`, not `rejected`. They did not decide
 * anything, and recording that they had would be a lie the reports would repeat.
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
                step: $context->step,
                comment: $comment,
                metadata: $decision->metadata,
            );

            ($this->failRequest)($request, $context->step, $decision->actor, $comment);

            return $request;
        });
    }
}
