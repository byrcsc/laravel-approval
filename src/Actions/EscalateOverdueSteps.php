<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Contracts\EscalationStrategy;
use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Enums\EscalationAction;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Enums\StepState;
use ByRcsc\LaravelApproval\Events\StepOverdue;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStep;
use ByRcsc\LaravelApproval\Support\Attributes;
use ByRcsc\LaravelApproval\Support\EscalationStrategies;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Throwable;

/**
 * The sweep behind `approval:escalate`: find the levels whose deadline has
 * passed, and apply whatever each one's policy says.
 *
 * Two properties matter more than anything else here, because this runs
 * unattended on a schedule.
 *
 * It is idempotent. `escalated_at` is the guard, so a command on a one-minute
 * cron escalates each step exactly once no matter how far behind the schedule
 * falls. A level that is still late tomorrow is not escalated again - being
 * late is a state, and it was already reported.
 *
 * It is not all-or-nothing. Each step gets its own transaction, so one
 * misconfigured level cannot stop the other four hundred from being handled.
 * A step whose strategy throws rolls back entirely, escalation mark included,
 * and is therefore picked up again on the next run - which is the right
 * behavior for the transient failures that dominate here.
 */
final class EscalateOverdueSteps
{
    public function __construct(
        private readonly Container $container,
        private readonly RecordAction $recordAction,
    ) {}

    /**
     * @return array{escalated: list<string>, failed: array<string, string>} step ids, and the ones that threw
     */
    public function __invoke(): array
    {
        $escalated = [];
        $failed = [];

        foreach ($this->overdueSteps() as $step) {
            $key = $step->getKey();
            $id = is_scalar($key) ? (string) $key : '';

            try {
                if ($this->escalate($step)) {
                    $escalated[] = $id;
                }
            } catch (Throwable $exception) {
                $failed[$id] = $exception->getMessage();
            }
        }

        return ['escalated' => $escalated, 'failed' => $failed];
    }

    private function escalate(ApprovalRequestStep $step): bool
    {
        return DB::transaction(function () use ($step): bool {
            $request = $step->request;

            if ($request === null) {
                return false;
            }

            $request->newQuery()->whereKey($request->getKey())->lockForUpdate()->get();
            $request->refresh();
            $step->refresh();

            // Re-checked under the lock: somebody may have decided the level
            // between the query above and this transaction.
            if (! $this->isStillOverdue($request, $step)) {
                return false;
            }

            $action = Attributes::enum($step, 'escalation_strategy', EscalationAction::class) ?? EscalationAction::Notify;

            // Marked before the strategy runs, so a strategy that decides the
            // level cannot be handed a step that still looks un-escalated.
            $step->forceFill(['escalated_at' => Date::now()])->save();

            ($this->recordAction)(
                request: $request,
                action: ActionType::Escalated,
                step: $step,
                metadata: [
                    'strategy' => $action->value,
                    'deadline_at' => Attributes::datetime($step, 'deadline_at')?->toIso8601String(),
                ],
            );

            Event::dispatch(new StepOverdue($request, $step, $action));

            $this->strategy($action)->handle($request, $step, $action, Attributes::object($step, 'escalation_config'));

            return true;
        });
    }

    private function isStillOverdue(ApprovalRequest $request, ApprovalRequestStep $step): bool
    {
        $state = Attributes::enum($request, 'state', RequestState::class) ?? RequestState::Pending;
        $stepState = Attributes::enum($step, 'state', StepState::class) ?? StepState::Queued;
        $deadline = Attributes::datetime($step, 'deadline_at');

        return $state === RequestState::Pending
            && $stepState === StepState::Active
            && $step->getAttributeValue('escalated_at') === null
            && $deadline !== null
            && ! $deadline->isFuture();
    }

    /**
     * @return iterable<int, ApprovalRequestStep>
     */
    private function overdueSteps(): iterable
    {
        /** @var iterable<int, ApprovalRequestStep> */
        return ApprovalRequestStep::query()
            ->where('state', StepState::Active->value)
            ->whereNull('escalated_at')
            ->whereNotNull('deadline_at')
            ->where('deadline_at', '<=', Date::now())
            ->whereHas('request', function ($query): void {
                $query->where('state', RequestState::Pending->value);
            })
            ->orderBy('deadline_at')
            ->get();
    }

    private function strategy(EscalationAction $action): EscalationStrategy
    {
        /** @var EscalationStrategy */
        return $this->container->make(EscalationStrategies::for($action));
    }
}
