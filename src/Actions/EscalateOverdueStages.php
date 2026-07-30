<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Contracts\EscalationStrategy;
use ByRcsc\LaravelApproval\Data\StageHealth;
use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Enums\EscalationAction;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Enums\StageStatus;
use ByRcsc\LaravelApproval\Events\StageOverdue;
use ByRcsc\LaravelApproval\Events\StageOverdueReminder;
use ByRcsc\LaravelApproval\Events\StageStranded;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStage;
use ByRcsc\LaravelApproval\Support\Attributes;
use ByRcsc\LaravelApproval\Support\EscalationStrategies;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Throwable;

/**
 * The sweep behind `approval:escalate`: find the stages whose deadline has
 * passed, and apply whatever each one's policy says.
 *
 * Two properties matter more than anything else here, because this runs
 * unattended on a schedule.
 *
 * Escalation is idempotent. `escalated_at` makes each strategy run once.
 * Stages with `remind_every_minutes` may emit later reminder actions and
 * events, but never execute the strategy again.
 *
 * It is not all-or-nothing. Each stage gets its own transaction, so one
 * misconfigured stage cannot stop the other four hundred from being handled.
 * A stage whose strategy throws rolls back entirely, escalation mark included,
 * and is therefore picked up again on the next run - which is the right
 * behavior for the transient failures that dominate here.
 */
final class EscalateOverdueStages
{
    public function __construct(
        private readonly Container $container,
        private readonly RecordAction $recordAction,
        private readonly ScanActiveStageHealth $healthScan,
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

        foreach ($this->overdueStages() as $stage) {
            $key = $stage->getKey();
            $id = is_scalar($key) ? (string) $key : '';

            try {
                $outcome = $this->process($stage);

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

    private function process(ApprovalRequestStage $stage): ?string
    {
        return DB::transaction(function () use ($stage): ?string {
            $request = $stage->request;

            if ($request === null) {
                return null;
            }

            $request->newQuery()->whereKey($request->getKey())->lockForUpdate()->get();
            $request->refresh();
            $stage->refresh();

            // Re-checked under the lock: somebody may have decided the stage
            // between the query above and this transaction.
            if (! $this->isStillOverdue($request, $stage)) {
                return null;
            }

            $action = Attributes::enum($stage, 'escalation_strategy', EscalationAction::class) ?? EscalationAction::RecordOnly;

            if ($stage->getAttributeValue('escalated_at') !== null) {
                $stage->forceFill(['last_reminded_at' => Date::now()])->save();

                ($this->recordAction)(
                    request: $request,
                    action: ActionType::Reminded,
                    stage: $stage,
                    metadata: [
                        'automatic' => true,
                        'strategy' => $action->value,
                        'deadline_at' => Attributes::datetime($stage, 'deadline_at')?->toIso8601String(),
                    ],
                );

                Event::dispatch(new StageOverdueReminder($request, $stage, $action));

                return 'reminded';
            }

            // Marked before the strategy runs, so a strategy that decides the
            // stage cannot be handed a stage that still looks un-escalated.
            $stage->forceFill(['escalated_at' => Date::now()])->save();

            ($this->recordAction)(
                request: $request,
                action: ActionType::Escalated,
                stage: $stage,
                metadata: [
                    'automatic' => true,
                    'strategy' => $action->value,
                    'deadline_at' => Attributes::datetime($stage, 'deadline_at')?->toIso8601String(),
                ],
            );

            Event::dispatch(new StageOverdue($request, $stage, $action));

            $this->strategy($action)->handle($request, $stage, $action, Attributes::object($stage, 'escalation_config'));

            return 'escalated';
        });
    }

    private function isStillOverdue(ApprovalRequest $request, ApprovalRequestStage $stage): bool
    {
        $state = Attributes::enum($request, 'state', RequestState::class) ?? RequestState::Pending;
        $stageState = Attributes::enum($stage, 'state', StageStatus::class) ?? StageStatus::Queued;
        $deadline = Attributes::datetime($stage, 'deadline_at');

        return $state === RequestState::Pending
            && $stageState === StageStatus::Active
            && $deadline !== null
            && ! $deadline->isFuture()
            && (
                $stage->getAttributeValue('escalated_at') === null
                || $this->reminderIsDue($stage)
            );
    }

    /**
     * @return iterable<int, ApprovalRequestStage>
     */
    private function overdueStages(): iterable
    {
        /** @var iterable<int, ApprovalRequestStage> */
        return ApprovalRequestStage::query()
            ->where('state', StageStatus::Active->value)
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

    private function reminderIsDue(ApprovalRequestStage $stage): bool
    {
        $cadence = Attributes::nullableInt($stage, 'remind_every_minutes');
        $last = Attributes::datetime($stage, 'last_reminded_at')
            ?? Attributes::datetime($stage, 'escalated_at');

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

        $this->healthScan->each(function (ApprovalRequestStage $stage, StageHealth $health) use (&$stranded): bool {
            // A cheap look first: the lock below is only worth taking when the
            // marker has to move, and on a healthy install it almost never does.
            $marked = $stage->getAttributeValue('stranded_at') !== null;

            if ($health->stranded() === $marked) {
                return true;
            }

            DB::transaction(function () use ($stage, &$stranded): void {
                $request = $stage->request;

                if ($request === null) {
                    return;
                }

                $request->newQuery()->whereKey($request->getKey())->lockForUpdate()->get();
                $request->refresh();
                $stage->refresh();

                if (
                    Attributes::enum($request, 'state', RequestState::class) !== RequestState::Pending
                    || Attributes::enum($stage, 'state', StageStatus::class) !== StageStatus::Active
                ) {
                    return;
                }

                $health = StageHealth::inspect($stage);

                if (! $health->stranded()) {
                    if ($stage->getAttributeValue('stranded_at') !== null) {
                        $stage->forceFill(['stranded_at' => null])->save();
                    }

                    return;
                }

                if ($stage->getAttributeValue('stranded_at') !== null) {
                    return;
                }

                $stage->forceFill(['stranded_at' => Date::now()])->save();
                $key = $stage->getKey();
                $stranded[] = is_scalar($key) ? (string) $key : '';

                Event::dispatch(new StageStranded(
                    $request,
                    $stage,
                    $health->livingHolders,
                    $health->requiredApprovals,
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
