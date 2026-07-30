<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Enums\StageStatus;
use ByRcsc\LaravelApproval\Events\ApprovalCompleted;
use ByRcsc\LaravelApproval\Events\StageCompleted;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStage;
use ByRcsc\LaravelApproval\Support\Attributes;
use Illuminate\Support\Facades\Event;

/**
 * A stage is settled: move the request on, or finish it.
 *
 * Separate from {@see ApproveStage} because a stage can be completed without
 * anybody deciding - an escalation strategy can decide one on the clock's behalf,
 * and it should reach the same end state by the same route rather than by a
 * second implementation that drifts from this one.
 *
 * Expects the caller to hold the request's row lock and to have already
 * established that the stage's approval rule is actually satisfied.
 */
final class CompleteStage
{
    public function __construct(
        private readonly ActivateStage $activateStage,
        private readonly ApplyDraft $applyDraft,
    ) {}

    public function __invoke(ApprovalRequest $request, ApprovalRequestStage $stage): void
    {
        $stage->forceFill(['state' => StageStatus::Approved])->save();

        $next = $this->nextStage($request, $stage);

        if ($next !== null) {
            ($this->activateStage)($next);

            $request->forceFill(['current_stage_sequence' => Attributes::int($next, 'sequence')])->save();

            Event::dispatch(new StageCompleted($request, $stage, $next));

            return;
        }

        Event::dispatch(new StageCompleted($request, $stage));

        // Fired before the draft lands, so a listener still sees the record as
        // the approvers saw it. Whether the request ends `approved` or
        // `conflicted` is the next line's decision, not this one's.
        Event::dispatch(new ApprovalCompleted($request));

        ($this->applyDraft)($request);
    }

    private function nextStage(ApprovalRequest $request, ApprovalRequestStage $stage): ?ApprovalRequestStage
    {
        /** @var ApprovalRequestStage|null */
        return $request->stages()
            ->where('state', StageStatus::Queued->value)
            ->where('sequence', '>', Attributes::int($stage, 'sequence'))
            ->orderBy('sequence')
            ->first();
    }
}
