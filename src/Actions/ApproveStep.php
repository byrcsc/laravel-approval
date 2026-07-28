<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Data\ApprovalDecision;
use ByRcsc\LaravelApproval\Data\Ballot;
use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Events\StepApproved;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Records one approval, and advances the request if that was the last one the
 * level needed.
 *
 * The whole thing runs under a row lock on the request. Two approvers deciding
 * in the same second is the ordinary case, not the exotic one, and without the
 * lock both would read a quorum of one-short and both would advance the level.
 */
final class ApproveStep
{
    public function __construct(
        private readonly AuthorizeDecision $authorize,
        private readonly RecordAction $recordAction,
        private readonly CarryStep $carryStep,
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
                step: $context->step,
                comment: $decision->comment(),
                metadata: $decision->metadata,
            );

            // Recounted after the vote rather than incremented, so the count is
            // read from the audit trail that will still be there tomorrow.
            $ballot = Ballot::for($context->step);

            Event::dispatch(new StepApproved(
                $request,
                $context->step,
                $decision->actor,
                $ballot->votes(),
                $ballot->quorum(),
            ));

            if ($ballot->isCarried()) {
                ($this->carryStep)($request, $context->step);
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
