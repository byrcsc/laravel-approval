<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Data\ApprovalDecision;
use ByRcsc\LaravelApproval\Data\StageProgress;
use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Events\StageApprovalRecorded;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Records one approval, and advances the request if that was the last one the
 * stage needed.
 *
 * The whole thing runs under a row lock on the request. Two approvers deciding
 * in the same second is the ordinary case, not the exotic one, and without the
 * lock both would read one approval short of the rule and both would advance the stage.
 */
final class ApproveStage
{
    public function __construct(
        private readonly AuthorizeDecision $authorize,
        private readonly RecordAction $recordAction,
        private readonly CompleteStage $completeStage,
    ) {}

    public function __invoke(ApprovalRequest $request, ApprovalDecision $decision): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $decision): ApprovalRequest {
            $this->lock($request);

            $context = ($this->authorize)($request, $decision->actor);

            ($this->recordAction)(
                request: $request,
                action: ActionType::Approved,
                actor: $decision->actor,
                stage: $context->stage,
                comment: $decision->comment(),
                metadata: $decision->metadata,
            );

            $progress = StageProgress::for($context->stage);

            Event::dispatch(new StageApprovalRecorded(
                $request,
                $context->stage,
                $decision->actor,
                $progress->approvalsReceived,
                $progress->approvalsRequired,
            ));

            if ($progress->isSatisfied()) {
                ($this->completeStage)($request, $context->stage);
            }

            return $request;
        });
    }

    /**
     * Takes the row lock and reloads, so what the checks below read is what the
     * database holds rather than what the caller happened to have loaded.
     */
    private function lock(ApprovalRequest $request): void
    {
        $request->newQuery()->whereKey($request->getKey())->lockForUpdate()->get();

        $request->refresh();
    }
}
