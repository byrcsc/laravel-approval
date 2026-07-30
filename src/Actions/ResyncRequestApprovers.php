<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Data\AssignmentSnapshot;
use ByRcsc\LaravelApproval\Data\StageHealth;
use ByRcsc\LaravelApproval\Data\StageProgress;
use ByRcsc\LaravelApproval\Data\StageSnapshot;
use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Enums\ApproverSource;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Enums\StageStatus;
use ByRcsc\LaravelApproval\Events\ApproversResynced;
use ByRcsc\LaravelApproval\Exceptions\AlreadyDecidedException;
use ByRcsc\LaravelApproval\Exceptions\InvalidWorkflowException;
use ByRcsc\LaravelApproval\Exceptions\WorkflowNotFoundException;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestAssignment;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStage;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflowStage;
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
 * It only touches stages that have not been decided, and never removes an assignment
 * that has decided or been delegated: both are records of something a person did,
 * and a resync is a correction to the future, not to the past.
 *
 * Pruning is off by default. Adding the people who should now be asked is
 * almost always safe; removing the ones who should not is a judgement about
 * whether their opinion still counts, and that belongs to whoever runs this.
 */
final class ResyncRequestApprovers
{
    public function __construct(
        private readonly ResolveStageApprovers $resolveApprovers,
        private readonly RecordAction $recordAction,
        private readonly CompleteStage $completeStage,
    ) {}

    /**
     * @param  Model  $actor  the operator this rebuild is attributed to
     * @param  bool  $prune  also drop assignments the workflow no longer names
     * @return array<int, array{added: list<string>, removed: list<string>}> keyed by stage sequence
     */
    public function __invoke(ApprovalRequest $request, Model $actor, bool $prune = false): array
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
            $requester = $request->requester;

            if (! $approvable instanceof Model || ! $requester instanceof Model) {
                throw AlreadyDecidedException::hasNoSubject($request);
            }

            $slug = Attributes::string($workflow, 'slug');

            /** @var array<int, ApprovalWorkflowStage> $workflowStages */
            $workflowStages = $workflow->stages()
                ->with(ResolveStageApprovers::ordered())
                ->get()
                ->keyBy(fn (ApprovalWorkflowStage $stage): int => Attributes::int($stage, 'sequence'))
                ->all();

            $changes = [];
            $created = [];

            foreach ($this->openStages($request) as $stage) {
                $sequence = Attributes::int($stage, 'sequence');
                $workflowStage = $workflowStages[$sequence] ?? null;

                if ($workflowStage === null) {
                    // The stage was deleted from the workflow. The request
                    // stage keeps its own snapshot and runs as it always would
                    // have; there is nothing current to resync it against.
                    continue;
                }

                $change = $this->resyncStage($stage, $workflowStage, $slug, $approvable, $requester, $prune, $created);

                if ($change['added'] !== [] || $change['removed'] !== []) {
                    $changes[$sequence] = $change;
                }
            }

            if ($changes !== []) {
                ($this->recordAction)(
                    request: $request,
                    action: ActionType::Resynced,
                    actor: $actor,
                    metadata: ['stages' => $changes, 'pruned' => $prune],
                );

                Event::dispatch(new ApproversResynced($request, $changes, $actor, $created));

                $this->completeActiveStageIfSatisfied($request);
            }

            return $changes;
        });
    }

    /**
     * @param  list<ApprovalRequestAssignment>  $created  collects the assignment rows this resync writes
     * @return array{added: list<string>, removed: list<string>}
     */
    private function resyncStage(
        ApprovalRequestStage $stage,
        ApprovalWorkflowStage $workflowStage,
        string $slug,
        Model $approvable,
        Model $requester,
        bool $prune,
        array &$created,
    ): array {
        $snapshot = StageSnapshot::fromRequestStage($stage);
        $wanted = ($this->resolveApprovers)($workflowStage, $snapshot, $approvable, $requester, $slug);

        $health = StageHealth::inspect($stage);
        $progress = StageProgress::for($stage);

        /** @var array<string, ApprovalRequestAssignment> $held */
        $held = [];
        $represented = $progress->representedApproverIdentities();

        foreach ($stage->assignments()->orderBy('id')->get() as $assignment) {
            $assignmentKey = $this->assignmentKey($assignment);
            $held[$assignmentKey] = $assignment;
        }

        $added = [];

        foreach ($wanted as $assignment) {
            if (isset($represented[$assignment->key()])) {
                continue;
            }

            $created[] = $stage->assignments()->create(
                array_merge($assignment->toAttributes(), ['source' => ApproverSource::Resynced]),
            );

            $added[] = $assignment->key();
            $represented[$assignment->key()] = true;
        }

        $removed = $prune
            ? $this->prune($stage, $held, $wanted, $progress)
            : [];

        $this->guardApprovalRule($stage, $snapshot, $slug, $health);

        return ['added' => $added, 'removed' => $removed];
    }

    /**
     * @param  array<string, ApprovalRequestAssignment>  $held
     * @param  list<AssignmentSnapshot>  $wanted
     * @return list<string>
     */
    private function prune(
        ApprovalRequestStage $stage,
        array $held,
        array $wanted,
        StageProgress $progress,
    ): array {
        $keep = [];

        foreach ($wanted as $assignment) {
            $keep[$assignment->key()] = true;
        }

        $removed = [];

        foreach ($held as $key => $assignment) {
            if (isset($keep[$key])) {
                continue;
            }

            // A cast decision and a handed-over assignment are both things somebody did.
            if ($progress->hasDecision($assignment) || $assignment->getAttributeValue('delegated_to_id') !== null) {
                continue;
            }

            $assignment->delete();

            $removed[] = $key;
        }

        return $removed;
    }

    /**
     * A resync that leaves a stage unable to reach its required approvals has stranded the
     * request, so it fails and the transaction takes the changes with it.
     */
    private function guardApprovalRule(
        ApprovalRequestStage $stage,
        StageSnapshot $snapshot,
        string $slug,
        StageHealth $before,
    ): void {
        $assignments = $stage->assignments()->count();

        if ($assignments === 0) {
            throw InvalidWorkflowException::stageHasNoApprovers($slug, $snapshot->sequence);
        }

        $required = $snapshot->requiredApprovals;

        if ($required !== null && $required > $assignments) {
            throw InvalidWorkflowException::approvalRuleUnreachable($slug, $snapshot->sequence, $required, $assignments);
        }

        StageHealth::assertNotWorsened($stage, $slug, $before);
    }

    /**
     * @return iterable<int, ApprovalRequestStage>
     */
    private function openStages(ApprovalRequest $request): iterable
    {
        /** @var iterable<int, ApprovalRequestStage> */
        return $request->stages()
            ->whereIn('state', [StageStatus::Active->value, StageStatus::Queued->value])
            ->get();
    }

    private function completeActiveStageIfSatisfied(ApprovalRequest $request): void
    {
        /** @var ApprovalRequestStage|null $stage */
        $stage = $request->stages()
            ->where('state', StageStatus::Active->value)
            ->first();

        if ($stage === null) {
            return;
        }

        if (! StageProgress::for($stage)->isSatisfied()) {
            return;
        }

        ($this->completeStage)($request, $stage);
    }

    private function assignmentKey(ApprovalRequestAssignment $assignment): string
    {
        return Attributes::string($assignment, 'approver_type').':'.Attributes::string($assignment, 'approver_id');
    }
}
