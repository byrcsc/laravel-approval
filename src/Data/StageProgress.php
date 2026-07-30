<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Data;

use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Enums\RejectionPolicy;
use ByRcsc\LaravelApproval\Enums\StageStatus;
use ByRcsc\LaravelApproval\Models\ApprovalRequestAssignment;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStage;
use ByRcsc\LaravelApproval\Support\Attributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A snapshot of an approval stage's assignments, decisions, and status.
 */
final readonly class StageProgress
{
    /**
     * @param  Collection<int, ApprovalRequestAssignment>  $assignments
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
        public int $possibleApprovals,
        public RejectionPolicy $rejectionPolicy,
        public StageStatus $status,
    ) {}

    public static function for(ApprovalRequestStage $stage): self
    {
        /** @var Collection<int, ApprovalRequestAssignment> $assignments */
        $assignments = $stage->relationLoaded('assignments')
            ? $stage->assignments->sortBy('id')->values()
            : $stage->assignments()->orderBy('id')->get();

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
            ->filter(fn (ApprovalRequestAssignment $assignment): bool => isset(
                $approvingIdentities[self::holderOf($assignment)],
            ))
            ->count();
        $decisionsRecorded = $assignments
            ->filter(fn (ApprovalRequestAssignment $assignment): bool => isset(
                $decidingIdentities[self::holderOf($assignment)],
            ))
            ->count();
        $rejectionPolicy = Attributes::enum($stage, 'rejection_policy', RejectionPolicy::class)
            ?? RejectionPolicy::ApprovalImpossible;

        return new self(
            assignments: $assignments,
            decidingIdentities: $decidingIdentities,
            assignedApprovers: $assignments->count(),
            decisionsRecorded: $decisionsRecorded,
            approvalsReceived: $approvalsReceived,
            approvalsRequired: $approvalsRequired,
            remainingApprovals: max(0, $approvalsRequired - $approvalsReceived),
            rejectionsReceived: $actions->filter(
                fn ($action): bool => Attributes::enum($action, 'action', ActionType::class) === ActionType::Rejected,
            )->count(),
            possibleApprovals: $approvalsReceived + $assignments->count() - $decisionsRecorded,
            rejectionPolicy: $rejectionPolicy,
            status: StageStatus::from(
                (Attributes::enum($stage, 'state', StageStatus::class) ?? StageStatus::Queued)->value,
            ),
        );
    }

    public function isSatisfied(): bool
    {
        return $this->approvalsReceived >= $this->approvalsRequired;
    }

    public function isRejected(): bool
    {
        return match ($this->rejectionPolicy) {
            RejectionPolicy::AnyRejectionVetoes => $this->rejectionsReceived > 0,
            RejectionPolicy::ApprovalImpossible => $this->possibleApprovals < $this->approvalsRequired,
        };
    }

    public function assignmentFor(Model $actor): ?ApprovalRequestAssignment
    {
        $key = $actor->getMorphClass().':'.self::stringKey($actor);

        return $this->assignments->first(
            fn (ApprovalRequestAssignment $assignment): bool => self::holderOf($assignment) === $key,
        );
    }

    public function hasDelegatedAssignment(Model $actor): bool
    {
        $key = $actor->getMorphClass().':'.self::stringKey($actor);

        return $this->assignments->contains(function (ApprovalRequestAssignment $assignment) use ($key): bool {
            return self::originalApproverOf($assignment) === $key
                && self::delegateOf($assignment) !== null;
        });
    }

    public function hasDecision(ApprovalRequestAssignment $assignment): bool
    {
        return isset($this->decidingIdentities[self::holderOf($assignment)]);
    }

    /**
     * Every assignment holder, whether or not they have responded.
     *
     * @return list<Model>
     */
    public function assignmentHolders(): array
    {
        $holders = [];

        foreach ($this->assignments as $assignment) {
            $holder = self::holderModelOf($assignment);

            if ($holder instanceof Model) {
                $holders[] = $holder;
            }
        }

        return $holders;
    }

    /**
     * The assignment holders who have not yet recorded a decision - the people
     * the stage is still waiting on.
     *
     * @return list<Model>
     */
    public function pendingAssignmentHolders(): array
    {
        $holders = [];

        foreach ($this->assignments as $assignment) {
            if (isset($this->decidingIdentities[self::holderOf($assignment)])) {
                continue;
            }

            $holder = self::holderModelOf($assignment);

            if ($holder instanceof Model) {
                $holders[] = $holder;
            }
        }

        return $holders;
    }

    private static function holderModelOf(ApprovalRequestAssignment $assignment): ?Model
    {
        $relation = $assignment->getAttributeValue('delegated_to_id') === null
            ? 'approver'
            : 'delegatedTo';
        $holder = $assignment->relationLoaded($relation)
            ? $assignment->getRelation($relation)
            : $assignment->{$relation};

        return $holder instanceof Model ? $holder : null;
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

    private static function holderOf(ApprovalRequestAssignment $assignment): string
    {
        return self::delegateOf($assignment) ?? self::originalApproverOf($assignment);
    }

    private static function originalApproverOf(ApprovalRequestAssignment $assignment): string
    {
        return Attributes::string($assignment, 'approver_type')
            .':'.Attributes::string($assignment, 'approver_id');
    }

    private static function delegateOf(ApprovalRequestAssignment $assignment): ?string
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
