<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Data\ApproverSlot;
use ByRcsc\LaravelApproval\Data\Ballot;
use ByRcsc\LaravelApproval\Data\LevelSnapshot;
use ByRcsc\LaravelApproval\Data\StepHealth;
use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Enums\ApproverSource;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Enums\StepState;
use ByRcsc\LaravelApproval\Events\ApproversResynced;
use ByRcsc\LaravelApproval\Exceptions\AlreadyDecidedException;
use ByRcsc\LaravelApproval\Exceptions\InvalidWorkflowException;
use ByRcsc\LaravelApproval\Exceptions\WorkflowNotFoundException;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStep;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStepApprover;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflowLevel;
use ByRcsc\LaravelApproval\Support\Attributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Re-runs the workflow's approver rules against a request already in flight.
 *
 * Approver snapshots go stale on purpose - that is what makes an audit trail
 * mean something. This is the sanctioned way to overrule that, for when a
 * reorganisation has left half a department's pending requests pointing at
 * people who no longer approve anything.
 *
 * It only touches steps that have not been decided, and never removes a seat
 * that has voted or been delegated: both are records of something a person did,
 * and a resync is a correction to the future, not to the past.
 *
 * Pruning is off by default. Adding the people who should now be asked is
 * almost always safe; removing the ones who should not is a judgement about
 * whether their opinion still counts, and that belongs to whoever runs this.
 */
final class ResyncRequestApprovers
{
    public function __construct(
        private readonly ResolveLevelApprovers $resolveApprovers,
        private readonly RecordAction $recordAction,
        private readonly CarryStep $carryStep,
    ) {}

    /**
     * @param  bool  $prune  also drop seats the workflow no longer names
     * @return array<int, array{added: list<string>, removed: list<string>}> keyed by step sequence
     */
    public function __invoke(ApprovalRequest $request, ?Model $actor = null, bool $prune = false): array
    {
        return DB::transaction(function () use ($request, $actor, $prune): array {
            $request->newQuery()->whereKey($request->getKey())->lockForUpdate()->get();
            $request->refresh();

            $state = Attributes::enum($request, 'state', RequestState::class) ?? RequestState::Pending;

            if ($state !== RequestState::Pending) {
                throw AlreadyDecidedException::requestIsClosed($request, $state);
            }

            $workflow = $request->workflow ?? throw WorkflowNotFoundException::forRequest($request);
            $approvable = $request->approvable;
            $submitter = $request->submitter;

            if (! $approvable instanceof Model || ! $submitter instanceof Model) {
                throw AlreadyDecidedException::retryHasNoSubject($request);
            }

            $slug = Attributes::string($workflow, 'slug');

            /** @var array<int, ApprovalWorkflowLevel> $levels */
            $levels = $workflow->levels()
                ->with(ResolveLevelApprovers::ordered())
                ->get()
                ->keyBy(fn (ApprovalWorkflowLevel $level): int => Attributes::int($level, 'sequence'))
                ->all();

            $changes = [];

            foreach ($this->openSteps($request) as $step) {
                $sequence = Attributes::int($step, 'sequence');
                $level = $levels[$sequence] ?? null;

                if ($level === null) {
                    // The level was deleted from the workflow. The step keeps
                    // its own snapshot and runs as it always would have; there
                    // is nothing current to resync it against.
                    continue;
                }

                $change = $this->resyncStep($step, $level, $slug, $approvable, $submitter, $prune);

                if ($change['added'] !== [] || $change['removed'] !== []) {
                    $changes[$sequence] = $change;
                }
            }

            if ($changes !== []) {
                ($this->recordAction)(
                    request: $request,
                    action: ActionType::Resynced,
                    actor: $actor,
                    metadata: ['steps' => $changes, 'pruned' => $prune],
                );

                Event::dispatch(new ApproversResynced($request, $changes, $actor));

                $this->carryActiveStepIfSatisfied($request);
            }

            return $changes;
        });
    }

    /**
     * @return array{added: list<string>, removed: list<string>}
     */
    private function resyncStep(
        ApprovalRequestStep $step,
        ApprovalWorkflowLevel $level,
        string $slug,
        Model $approvable,
        Model $submitter,
        bool $prune,
    ): array {
        $snapshot = LevelSnapshot::fromStep($step);
        $wanted = ($this->resolveApprovers)($level, $snapshot, $approvable, $submitter, $slug);

        $health = StepHealth::inspect($step);
        $ballot = Ballot::for($step);

        /** @var array<string, ApprovalRequestStepApprover> $held */
        $held = [];
        $represented = $ballot->representedIdentities();

        foreach ($step->approverSlots()->orderBy('id')->get() as $seat) {
            $seatKey = $this->seatKey($seat);
            $held[$seatKey] = $seat;
        }

        $added = [];

        foreach ($wanted as $slot) {
            if (isset($represented[$slot->key()])) {
                continue;
            }

            $step->approverSlots()->create(
                array_merge($slot->toAttributes(), ['source' => ApproverSource::Resynced]),
            );

            $added[] = $slot->key();
            $represented[$slot->key()] = true;
        }

        $removed = $prune
            ? $this->prune($step, $held, $wanted, $ballot)
            : [];

        $this->guardQuorum($step, $snapshot, $slug, $health);

        return ['added' => $added, 'removed' => $removed];
    }

    /**
     * @param  array<string, ApprovalRequestStepApprover>  $held
     * @param  list<ApproverSlot>  $wanted
     * @return list<string>
     */
    private function prune(ApprovalRequestStep $step, array $held, array $wanted, Ballot $ballot): array
    {
        $keep = [];

        foreach ($wanted as $slot) {
            $keep[$slot->key()] = true;
        }

        $removed = [];

        foreach ($held as $key => $seat) {
            if (isset($keep[$key])) {
                continue;
            }

            // A cast vote and a handed-over seat are both things somebody did.
            if ($ballot->hasVoted($seat) || $seat->getAttributeValue('delegated_to_id') !== null) {
                continue;
            }

            $seat->delete();

            $removed[] = $key;
        }

        return $removed;
    }

    /**
     * A resync that leaves a level unable to reach its quorum has stranded the
     * request, so it fails and the transaction takes the changes with it.
     */
    private function guardQuorum(
        ApprovalRequestStep $step,
        LevelSnapshot $snapshot,
        string $slug,
        StepHealth $before,
    ): void {
        $seats = $step->approverSlots()->count();

        if ($seats === 0) {
            throw InvalidWorkflowException::levelHasNoApprovers($slug, $snapshot->sequence);
        }

        $required = $snapshot->requiredApprovals;

        if ($required !== null && $required > $seats) {
            throw InvalidWorkflowException::quorumUnreachable($slug, $snapshot->sequence, $required, $seats);
        }

        StepHealth::assertNotWorsened($step, $slug, $before);
    }

    /**
     * @return iterable<int, ApprovalRequestStep>
     */
    private function openSteps(ApprovalRequest $request): iterable
    {
        /** @var iterable<int, ApprovalRequestStep> */
        return $request->steps()
            ->whereIn('state', [StepState::Active->value, StepState::Queued->value])
            ->get();
    }

    private function carryActiveStepIfSatisfied(ApprovalRequest $request): void
    {
        /** @var ApprovalRequestStep|null $step */
        $step = $request->steps()
            ->where('state', StepState::Active->value)
            ->first();

        if ($step === null) {
            return;
        }

        if (! Ballot::for($step)->isCarried()) {
            return;
        }

        ($this->carryStep)($request, $step);
    }

    private function seatKey(ApprovalRequestStepApprover $seat): string
    {
        return Attributes::string($seat, 'approver_type').':'.Attributes::string($seat, 'approver_id');
    }
}
