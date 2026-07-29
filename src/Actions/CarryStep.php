<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Enums\StepState;
use ByRcsc\LaravelApproval\Events\ApprovalCompleted;
use ByRcsc\LaravelApproval\Events\LevelCompleted;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStep;
use ByRcsc\LaravelApproval\Support\Attributes;
use Illuminate\Support\Facades\Event;

/**
 * A level is settled: move the request on, or finish it.
 *
 * Separate from {@see ApproveStep} because a level can be carried without
 * anybody voting - an escalation strategy can decide one on the clock's behalf,
 * and it should reach the same end state by the same route rather than by a
 * second implementation that drifts from this one.
 *
 * Expects the caller to hold the request's row lock and to have already
 * established that the level is actually carried.
 */
final class CarryStep
{
    public function __construct(
        private readonly ActivateStep $activateStep,
        private readonly ApplyDraft $applyDraft,
    ) {}

    public function __invoke(ApprovalRequest $request, ApprovalRequestStep $step): void
    {
        $step->forceFill(['state' => StepState::Approved])->save();

        $next = $this->nextStep($request, $step);

        if ($next !== null) {
            ($this->activateStep)($next);

            $request->forceFill(['current_level_sequence' => Attributes::int($next, 'sequence')])->save();

            Event::dispatch(new LevelCompleted($request, $step, $next));

            return;
        }

        Event::dispatch(new LevelCompleted($request, $step));

        // Fired before the draft lands, so a listener still sees the record as
        // the approvers saw it. Whether the request ends `approved` or
        // `conflicted` is the next line's decision, not this one's.
        Event::dispatch(new ApprovalCompleted($request));

        ($this->applyDraft)($request);
    }

    private function nextStep(ApprovalRequest $request, ApprovalRequestStep $step): ?ApprovalRequestStep
    {
        /** @var ApprovalRequestStep|null */
        return $request->steps()
            ->where('state', StepState::Queued->value)
            ->where('sequence', '>', Attributes::int($step, 'sequence'))
            ->orderBy('sequence')
            ->first();
    }
}
