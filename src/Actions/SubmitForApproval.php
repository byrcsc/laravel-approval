<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Data\AssignmentSnapshot;
use ByRcsc\LaravelApproval\Data\StageSnapshot;
use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Enums\RejectionPolicy;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Enums\StageStatus;
use ByRcsc\LaravelApproval\Events\ApprovalCompleted;
use ByRcsc\LaravelApproval\Events\ApprovalSubmitted;
use ByRcsc\LaravelApproval\Exceptions\ApprovalInProgressException;
use ByRcsc\LaravelApproval\Exceptions\InvalidDraftException;
use ByRcsc\LaravelApproval\Exceptions\InvalidWorkflowException;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStage;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflow;
use ByRcsc\LaravelApproval\Support\Attributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Turns a workflow definition and a record into a request that can be decided.
 *
 * The order matters more than it looks. Everything that can fail - the
 * workflow's shape, the resolvers, the draft's keys - is settled before the
 * transaction opens, so a misconfigured stage three cannot leave a half-built
 * request behind. Once inside, the work is only writes.
 *
 * The resolvers run here and nowhere else. What they return is copied into
 * approver assignments and never consulted again, so an org chart that changes
 * tomorrow does not change who was asked today. Getting a stale assignment fixed is
 * deliberate work: delegate it, reassign it, or resync the request.
 */
final class SubmitForApproval
{
    public function __construct(
        private readonly ResolveStageApprovers $resolveApprovers,
        private readonly EvaluateStageCondition $evaluateCondition,
        private readonly RecordAction $recordAction,
        private readonly ActivateStage $activateStage,
        private readonly ApplyDraft $applyDraft,
    ) {}

    /**
     * @param  array<string, mixed>|null  $newAttributes  null gates the record itself rather than an edit to it
     * @param  array<string, mixed>  $metadata
     */
    public function __invoke(
        Model $approvable,
        ApprovalWorkflow $workflow,
        ?array $newAttributes,
        Model $requester,
        ?string $comment = null,
        array $metadata = [],
    ): ApprovalRequest {
        if (! $approvable->exists) {
            throw InvalidDraftException::unsavedApprovable($approvable);
        }

        $this->guardAgainstOpenRequest($approvable);

        $draft = $this->draft($approvable, $newAttributes);
        $plan = $this->plan($workflow, $approvable, $requester, $draft['new']);

        return DB::transaction(function () use ($approvable, $workflow, $requester, $plan, $draft, $comment, $metadata): ApprovalRequest {
            $this->lockApprovable($approvable);
            $this->guardAgainstOpenRequest($approvable);

            $request = ApprovalRequest::create([
                'approvable_type' => $approvable->getMorphClass(),
                'approvable_id' => $approvable->getKey(),
                'workflow_id' => $workflow->getKey(),
                'state' => RequestState::Pending,
                'current_stage_sequence' => $plan[0]['snapshot']->sequence,
                'new_attributes' => $draft['new'],
                'original_attributes' => $draft['original'],
                'requester_type' => $requester->getMorphClass(),
                'requester_id' => $requester->getKey(),
                'submitted_at' => Date::now(),
            ]);

            $stages = array_map(
                fn (array $stage): ApprovalRequestStage => $this->writeStage($request, $stage['snapshot'], $stage['approvers']),
                $plan,
            );

            // The engine's key wins over the caller's: which workflow ran is
            // the trail's fact to record, not the requester's to claim.
            ($this->recordAction)(
                request: $request,
                action: ActionType::Submitted,
                actor: $requester,
                comment: $comment,
                metadata: array_merge($metadata, ['workflow' => Attributes::string($workflow, 'slug')]),
            );

            foreach ($stages as $stage) {
                if (Attributes::enum($stage, 'state', StageStatus::class) === StageStatus::Skipped) {
                    ($this->recordAction)(
                        request: $request,
                        action: ActionType::StageSkipped,
                        stage: $stage,
                        metadata: ['condition' => Attributes::nullableString($stage, 'condition')],
                    );
                }
            }

            /** @var ApprovalRequestStage|null $first */
            $first = collect($stages)->first(
                fn (ApprovalRequestStage $stage): bool => Attributes::enum($stage, 'state', StageStatus::class) === StageStatus::Queued,
            );

            if ($first !== null) {
                ($this->activateStage)($first);
                $request->forceFill(['current_stage_sequence' => Attributes::int($first, 'sequence')])->save();
            } else {
                // Every stage was skipped. The pointer rests where a fully run
                // chain would leave it: on the last stage.
                $request->forceFill(['current_stage_sequence' => Attributes::int($stages[count($stages) - 1], 'sequence')])->save();
            }

            Event::dispatch(new ApprovalSubmitted($request, $first));

            if ($first === null) {
                Event::dispatch(new ApprovalCompleted($request));
                ($this->applyDraft)($request);
            }

            return $request;
        });
    }

    /**
     * @param  list<AssignmentSnapshot>  $approvers
     */
    private function writeStage(ApprovalRequest $request, StageSnapshot $snapshot, array $approvers): ApprovalRequestStage
    {
        /** @var ApprovalRequestStage $stage */
        $stage = $request->stages()->create($snapshot->toStageAttributes());

        foreach ($approvers as $approver) {
            $stage->assignments()->create($approver->toAttributes());
        }

        return $stage;
    }

    /**
     * Everything the workflow says, worked out in full before anything is
     * written. A workflow that cannot reach a decision fails here.
     *
     * @param  array<string, mixed>|null  $newAttributes
     * @return non-empty-list<array{snapshot: StageSnapshot, approvers: list<AssignmentSnapshot>}>
     */
    private function plan(
        ApprovalWorkflow $workflow,
        Model $approvable,
        Model $requester,
        ?array $newAttributes,
    ): array {
        $slug = Attributes::string($workflow, 'slug');

        if (! Attributes::bool($workflow, 'active')) {
            throw InvalidWorkflowException::inactive($slug);
        }

        $stages = $workflow->stages()->with(ResolveStageApprovers::ordered())->get();

        if ($stages->isEmpty()) {
            throw InvalidWorkflowException::hasNoStages($slug);
        }

        $plan = [];
        $proposed = $this->proposed($approvable, $newAttributes);

        foreach ($stages as $stage) {
            $this->guardRejectionPolicy($slug, $stage);
            $snapshot = StageSnapshot::fromWorkflowStage($stage);
            $passes = ($this->evaluateCondition)($snapshot, $proposed, $requester, $slug);
            $snapshot = $snapshot->withConditionResult($passes);
            $approvers = ($this->resolveApprovers)($stage, $snapshot, $approvable, $requester, $slug);

            if ($passes && $approvers === []) {
                throw InvalidWorkflowException::stageHasNoApprovers($slug, $snapshot->sequence);
            }

            $this->guardApprovalRule($slug, $snapshot, count($approvers));

            if (! $passes) {
                $plan[] = ['snapshot' => $snapshot, 'approvers' => []];

                continue;
            }

            $plan[] = ['snapshot' => $snapshot, 'approvers' => $approvers];
        }

        // The empty case threw above; this is for the analyser's benefit.
        assert($plan !== []);

        return $plan;
    }

    /**
     * Conditions judge the proposal, including held draft values, without
     * mutating the real model or changing what approver resolvers receive.
     *
     * @param  array<string, mixed>|null  $newAttributes
     */
    private function proposed(Model $approvable, ?array $newAttributes): Model
    {
        if ($newAttributes === null) {
            return $approvable;
        }

        $proposed = clone $approvable;
        $proposed->forceFill($newAttributes);

        return $proposed;
    }

    private function guardApprovalRule(string $slug, StageSnapshot $snapshot, int $available): void
    {
        $required = $snapshot->requiredApprovals;

        if ($required === null) {
            return;
        }

        if ($required < 1) {
            throw InvalidWorkflowException::approvalRuleNotPositive($slug, $snapshot->sequence, $required);
        }

        if ($required > $available) {
            throw InvalidWorkflowException::approvalRuleUnreachable($slug, $snapshot->sequence, $required, $available);
        }
    }

    private function guardRejectionPolicy(string $slug, Model $stage): void
    {
        $policy = $stage->getRawOriginal('rejection_policy');

        if ($policy === null) {
            return;
        }

        if (! is_string($policy) || RejectionPolicy::tryFrom($policy) === null) {
            throw InvalidWorkflowException::unknownRejectionPolicy(
                $slug,
                Attributes::int($stage, 'sequence'),
                is_scalar($policy) ? (string) $policy : get_debug_type($policy),
            );
        }
    }

    private function guardAgainstOpenRequest(Model $approvable): void
    {
        /** @var ApprovalRequest|null $open */
        $open = ApprovalRequest::query()
            ->forApprovable($approvable)
            ->unresolved()
            ->first();

        if ($open !== null) {
            throw ApprovalInProgressException::forApprovable($approvable, $open);
        }
    }

    private function lockApprovable(Model $approvable): void
    {
        // The record is the shared row every submission for this subject can
        // lock, including when no approval request exists yet.
        $locked = $approvable->newQuery()
            ->whereKey($approvable->getKey())
            ->lockForUpdate()
            ->first();

        if (! $locked instanceof Model) {
            throw InvalidDraftException::missingApprovable($approvable);
        }
    }

    /**
     * The draft, and what it was a draft of.
     *
     * `original` holds the values as the database has them, not as the model
     * currently carries them - the point of the snapshot is to detect the
     * record moving underneath the request, and an unsaved change in memory is
     * not that.
     *
     * @param  array<string, mixed>|null  $newAttributes
     * @return array{new: array<string, mixed>|null, original: array<string, mixed>|null}
     */
    private function draft(Model $approvable, ?array $newAttributes): array
    {
        if ($newAttributes === null) {
            return ['new' => null, 'original' => null];
        }

        if ($newAttributes === []) {
            throw InvalidDraftException::emptyDraft($approvable);
        }

        $this->guardDraftableAttributes($approvable, array_keys($newAttributes));

        // Read back rather than trusting the instance in hand. A model created
        // without naming a column that has a database default does not carry
        // that column at all, and snapshotting the null it reports would make
        // the stale check fire on a record nobody touched.
        $persisted = $approvable->newQuery()->whereKey($approvable->getKey())->first() ?? $approvable;

        $original = [];

        foreach (array_keys($newAttributes) as $key) {
            /** @var mixed $value */
            $value = $persisted->getRawOriginal($key);
            $original[$key] = $value;
        }

        return ['new' => $newAttributes, 'original' => $original];
    }

    /**
     * `approvableAttributes()` is the model's allow-list. Returning null - the
     * trait's default - means every attribute is fair game.
     *
     * @param  list<string>  $keys
     */
    private function guardDraftableAttributes(Model $approvable, array $keys): void
    {
        if (! method_exists($approvable, 'approvableAttributes')) {
            return;
        }

        /** @var mixed $allowed */
        $allowed = $approvable->approvableAttributes();

        if (! is_array($allowed)) {
            return;
        }

        $allowed = array_values(array_filter($allowed, is_string(...)));
        $rejected = array_values(array_diff($keys, $allowed));

        if ($rejected !== []) {
            throw InvalidDraftException::undraftableAttributes($approvable, $rejected, $allowed);
        }
    }
}
