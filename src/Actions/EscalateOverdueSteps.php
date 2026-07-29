<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Contracts\EscalationStrategy;
use ByRcsc\LaravelApproval\Data\StepHealth;
use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Enums\EscalationAction;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Enums\StepState;
use ByRcsc\LaravelApproval\Events\StepOverdue;
use ByRcsc\LaravelApproval\Events\StepStranded;
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
 * Escalation is idempotent. `escalated_at` makes each strategy run once.
 * Levels with `remind_every_minutes` may emit later reminder actions and
 * events, but never execute the strategy again.
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
        private readonly ScanActiveStepHealth $healthScan,
    ) {}

    /**
     * @return array{escalated: list<string>, reminded: list<string>, stranded: list<string>, failed: array<string, string>}
     */
    public function __invoke(): array
    {
        $escalated = [];
        $reminded = [];
        $failed = [];
        $stranded = $this->monitorStranded();

        foreach ($this->overdueSteps() as $step) {
            $key = $step->getKey();
            $id = is_scalar($key) ? (string) $key : '';

            try {
                $outcome = $this->process($step);

                if ($outcome === 'escalated') {
                    $escalated[] = $id;
                } elseif ($outcome === 'reminded') {
                    $reminded[] = $id;
                }
            } catch (Throwable $exception) {
                $failed[$id] = $exception->getMessage();
            }
        }

        return [
            'escalated' => $escalated,
            'reminded' => $reminded,
            'stranded' => $stranded,
            'failed' => $failed,
        ];
    }

    private function process(ApprovalRequestStep $step): ?string
    {
        return DB::transaction(function () use ($step): ?string {
            $request = $step->request;

            if ($request === null) {
                return null;
            }

            $request->newQuery()->whereKey($request->getKey())->lockForUpdate()->get();
            $request->refresh();
            $step->refresh();

            // Re-checked under the lock: somebody may have decided the level
            // between the query above and this transaction.
            if (! $this->isStillOverdue($request, $step)) {
                return null;
            }

            $action = Attributes::enum($step, 'escalation_strategy', EscalationAction::class) ?? EscalationAction::Notify;

            if ($step->getAttributeValue('escalated_at') !== null) {
                $step->forceFill(['last_reminded_at' => Date::now()])->save();

                ($this->recordAction)(
                    request: $request,
                    action: ActionType::Reminded,
                    step: $step,
                    metadata: [
                        'strategy' => $action->value,
                        'deadline_at' => Attributes::datetime($step, 'deadline_at')?->toIso8601String(),
                    ],
                );

                Event::dispatch(new StepOverdue($request, $step, $action));

                return 'reminded';
            }

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

            return 'escalated';
        });
    }

    private function isStillOverdue(ApprovalRequest $request, ApprovalRequestStep $step): bool
    {
        $state = Attributes::enum($request, 'state', RequestState::class) ?? RequestState::Pending;
        $stepState = Attributes::enum($step, 'state', StepState::class) ?? StepState::Queued;
        $deadline = Attributes::datetime($step, 'deadline_at');

        return $state === RequestState::Pending
            && $stepState === StepState::Active
            && $deadline !== null
            && ! $deadline->isFuture()
            && (
                $step->getAttributeValue('escalated_at') === null
                || $this->reminderIsDue($step)
            );
    }

    /**
     * @return iterable<int, ApprovalRequestStep>
     */
    private function overdueSteps(): iterable
    {
        /** @var iterable<int, ApprovalRequestStep> */
        return ApprovalRequestStep::query()
            ->where('state', StepState::Active->value)
            ->whereNotNull('deadline_at')
            ->where('deadline_at', '<=', Date::now())
            ->where(function ($query): void {
                $query->whereNull('escalated_at')
                    ->orWhere(function ($reminder): void {
                        $reminder->whereNotNull('remind_every_minutes')
                            ->where('remind_every_minutes', '>', 0);
                    });
            })
            ->whereHas('request', function ($query): void {
                $query->where('state', RequestState::Pending->value);
            })
            ->orderBy('id')
            ->lazyById(200);
    }

    private function reminderIsDue(ApprovalRequestStep $step): bool
    {
        $cadence = Attributes::nullableInt($step, 'remind_every_minutes');
        $last = Attributes::datetime($step, 'last_reminded_at')
            ?? Attributes::datetime($step, 'escalated_at');

        return $cadence !== null
            && $cadence > 0
            && $last !== null
            && ! $last->copy()->addMinutes($cadence)->isFuture();
    }

    /**
     * @return list<string>
     */
    private function monitorStranded(): array
    {
        $stranded = [];

        $this->healthScan->each(function (ApprovalRequestStep $step, StepHealth $health) use (&$stranded): bool {
            // A cheap look first: the lock below is only worth taking when the
            // marker has to move, and on a healthy install it almost never does.
            $marked = $step->getAttributeValue('stranded_at') !== null;

            if ($health->stranded() === $marked) {
                return true;
            }

            DB::transaction(function () use ($step, &$stranded): void {
                $request = $step->request;

                if ($request === null) {
                    return;
                }

                $request->newQuery()->whereKey($request->getKey())->lockForUpdate()->get();
                $request->refresh();
                $step->refresh();

                if (
                    Attributes::enum($request, 'state', RequestState::class) !== RequestState::Pending
                    || Attributes::enum($step, 'state', StepState::class) !== StepState::Active
                ) {
                    return;
                }

                $health = StepHealth::inspect($step);

                if (! $health->stranded()) {
                    if ($step->getAttributeValue('stranded_at') !== null) {
                        $step->forceFill(['stranded_at' => null])->save();
                    }

                    return;
                }

                if ($step->getAttributeValue('stranded_at') !== null) {
                    return;
                }

                $step->forceFill(['stranded_at' => Date::now()])->save();
                $key = $step->getKey();
                $stranded[] = is_scalar($key) ? (string) $key : '';

                Event::dispatch(new StepStranded(
                    $request,
                    $step,
                    $health->living,
                    $health->quorum,
                ));
            });

            return true;
        });

        return $stranded;
    }

    private function strategy(EscalationAction $action): EscalationStrategy
    {
        /** @var EscalationStrategy */
        return $this->container->make(EscalationStrategies::for($action));
    }
}
