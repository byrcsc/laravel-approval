<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Data;

use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Enums\StageStatus;
use ByRcsc\LaravelApproval\Enums\StepState;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStep;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStepApprover;
use ByRcsc\LaravelApproval\Support\Attributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A snapshot of an approval stage's assignments, decisions, and status.
 */
final readonly class StageProgress
{
    /**
     * @param  Collection<int, ApprovalRequestStepApprover>  $assignments
     * @param  array<string, true>  $decidingIdentities
     */
    private function __construct(
        private Collection $assignments,
        private array $decidingIdentities,
        public int $assignedApprovers,
        public int $decisionsRecorded,
        public int $approvalsReceived,
        public int $approvalsRequired,
        public int $remainingApprovals,
        public int $rejectionsReceived,
        public StageStatus $status,
    ) {}

    public static function for(ApprovalRequestStep $stage): self
    {
        /** @var Collection<int, ApprovalRequestStepApprover> $assignments */
        $assignments = $stage->relationLoaded('approverSlots')
            ? $stage->approverSlots->sortBy('id')->values()
            : $stage->approverSlots()->orderBy('id')->get();

        $actions = $stage->relationLoaded('actions')
            ? $stage->actions
            : $stage->actions()->get();

        $decidingIdentities = [];
        $approvingIdentities = [];

        foreach ($actions as $action) {
            $type = Attributes::nullableString($action, 'actor_type');
            $id = $action->getAttributeValue('actor_id');
            $actionType = Attributes::enum($action, 'action', ActionType::class);

            if (
                $type !== null
                && (is_int($id) || is_string($id))
                && in_array($actionType, [ActionType::Approved, ActionType::Rejected, ActionType::Returned], true)
            ) {
                $identity = $type.':'.$id;
                $decidingIdentities[$identity] = true;

                if ($actionType === ActionType::Approved) {
                    $approvingIdentities[$identity] = true;
                }
            }
        }

        $approvalsRequired = Attributes::nullableInt($stage, 'required_approvals')
            ?? $assignments->count();
        $approvalsReceived = $assignments
            ->filter(fn (ApprovalRequestStepApprover $assignment): bool => isset(
                $approvingIdentities[self::holderOf($assignment)],
            ))
            ->count();

        return new self(
            assignments: $assignments,
            decidingIdentities: $decidingIdentities,
            assignedApprovers: $assignments->count(),
            decisionsRecorded: $actions->filter(
                fn ($action): bool => in_array(
                    Attributes::enum($action, 'action', ActionType::class),
                    [ActionType::Approved, ActionType::Rejected, ActionType::Returned],
                    true,
                ),
            )->count(),
            approvalsReceived: $approvalsReceived,
            approvalsRequired: $approvalsRequired,
            remainingApprovals: max(0, $approvalsRequired - $approvalsReceived),
            rejectionsReceived: $actions->filter(
                fn ($action): bool => Attributes::enum($action, 'action', ActionType::class) === ActionType::Rejected,
            )->count(),
            status: StageStatus::from(
                (Attributes::enum($stage, 'state', StepState::class) ?? StepState::Queued)->value,
            ),
        );
    }

    public function isSatisfied(): bool
    {
        return $this->approvalsReceived >= $this->approvalsRequired;
    }

    public function assignmentFor(Model $actor): ?ApprovalRequestStepApprover
    {
        $key = $actor->getMorphClass().':'.self::stringKey($actor);

        return $this->assignments->first(
            fn (ApprovalRequestStepApprover $assignment): bool => self::holderOf($assignment) === $key,
        );
    }

    public function hasDelegatedAssignment(Model $actor): bool
    {
        $key = $actor->getMorphClass().':'.self::stringKey($actor);

        return $this->assignments->contains(function (ApprovalRequestStepApprover $assignment) use ($key): bool {
            return self::originalApproverOf($assignment) === $key
                && self::delegateOf($assignment) !== null;
        });
    }

    public function hasDecision(ApprovalRequestStepApprover $assignment): bool
    {
        return isset($this->decidingIdentities[self::holderOf($assignment)]);
    }

    /**
     * @return list<Model>
     */
    public function assignmentHolders(): array
    {
        $holders = [];

        foreach ($this->assignments as $assignment) {
            $relation = $assignment->getAttributeValue('delegated_to_id') === null
                ? 'approver'
                : 'delegatedTo';
            $holder = $assignment->relationLoaded($relation)
                ? $assignment->getRelation($relation)
                : $assignment->{$relation};

            if ($holder instanceof Model) {
                $holders[] = $holder;
            }
        }

        return $holders;
    }

    /**
     * @return array<string, true>
     */
    public function representedApproverIdentities(): array
    {
        $represented = [];

        foreach ($this->assignments as $assignment) {
            $represented[self::originalApproverOf($assignment)] = true;

            $delegate = self::delegateOf($assignment);

            if ($delegate !== null) {
                $represented[$delegate] = true;
            }
        }

        return $represented;
    }

    private static function holderOf(ApprovalRequestStepApprover $assignment): string
    {
        return self::delegateOf($assignment) ?? self::originalApproverOf($assignment);
    }

    private static function originalApproverOf(ApprovalRequestStepApprover $assignment): string
    {
        return Attributes::string($assignment, 'approver_type')
            .':'.Attributes::string($assignment, 'approver_id');
    }

    private static function delegateOf(ApprovalRequestStepApprover $assignment): ?string
    {
        $type = Attributes::nullableString($assignment, 'delegated_to_type');
        $id = Attributes::nullableString($assignment, 'delegated_to_id');

        return $type === null || $id === null ? null : $type.':'.$id;
    }

    private static function stringKey(Model $model): string
    {
        $key = $model->getKey();

        return is_scalar($key) ? (string) $key : '';
    }
}
