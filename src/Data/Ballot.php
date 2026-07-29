<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Data;

use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStep;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStepApprover;
use ByRcsc\LaravelApproval\Support\Attributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Where a level's vote currently stands: who holds a seat, who has used it, and
 * whether that is enough.
 *
 * Quorum is counted in seats rather than people. A slot that has been delegated
 * belongs to the delegate and to nobody else - the original approver's name
 * stays on the row as a record of who was asked, not as a second way to vote.
 * Counting people instead would let one delegate holding two seats fill a
 * two-of-three quorum on their own.
 */
final readonly class Ballot
{
    /**
     * @param  Collection<int, ApprovalRequestStepApprover>  $slots
     * @param  array<string, true>  $voters  morph keys of everyone who has approved this step
     */
    private function __construct(
        private Collection $slots,
        private array $voters,
        private ?int $requiredApprovals,
    ) {}

    public static function for(ApprovalRequestStep $step): self
    {
        /** @var Collection<int, ApprovalRequestStepApprover> $slots */
        $slots = $step->relationLoaded('approverSlots')
            ? $step->approverSlots->sortBy('id')->values()
            : $step->approverSlots()->orderBy('id')->get();

        $voters = [];

        $actions = $step->relationLoaded('actions')
            ? $step->actions->where('action', ActionType::Approved)
            : $step->actions()->where('action', ActionType::Approved->value)->get();

        foreach ($actions as $action) {
            $type = Attributes::nullableString($action, 'actor_type');
            $id = $action->getAttributeValue('actor_id');

            if ($type !== null && (is_int($id) || is_string($id))) {
                $voters[$type.':'.$id] = true;
            }
        }

        return new self($slots, $voters, Attributes::nullableInt($step, 'required_approvals'));
    }

    /**
     * A null `required_approvals` means everybody, which can only be resolved
     * once the seats exist.
     */
    public function quorum(): int
    {
        return $this->requiredApprovals ?? $this->slots->count();
    }

    public function seats(): int
    {
        return $this->slots->count();
    }

    public function votes(): int
    {
        return $this->slots->filter($this->hasVoted(...))->count();
    }

    public function isCarried(): bool
    {
        return $this->votes() >= $this->quorum();
    }

    /**
     * The seat this actor may vote from, or null if they hold none.
     */
    public function seatFor(Model $actor): ?ApprovalRequestStepApprover
    {
        $key = $actor->getMorphClass().':'.self::stringKey($actor);

        return $this->slots->first(fn (ApprovalRequestStepApprover $slot): bool => self::holderOf($slot) === $key);
    }

    /**
     * Whether the actor is named on a seat they can no longer use, which is a
     * different message from holding no seat at all.
     */
    public function hasDelegatedSeat(Model $actor): bool
    {
        $key = $actor->getMorphClass().':'.self::stringKey($actor);

        return $this->slots->contains(function (ApprovalRequestStepApprover $slot) use ($key): bool {
            return self::originalOf($slot) === $key && self::delegateOf($slot) !== null;
        });
    }

    /**
     * The people who can currently act, one per seat - a delegate where the
     * seat was handed over, the original approver everywhere else. Records that
     * have since been deleted drop out rather than appearing as nulls.
     *
     * @return list<Model>
     */
    public function holders(): array
    {
        $holders = [];

        foreach ($this->slots as $slot) {
            $relation = $slot->getAttributeValue('delegated_to_id') === null
                ? 'approver'
                : 'delegatedTo';
            $holder = $slot->relationLoaded($relation)
                ? $slot->getRelation($relation)
                : $slot->{$relation};

            if ($holder instanceof Model) {
                $holders[] = $holder;
            }
        }

        return $holders;
    }

    /**
     * Every identity already represented by a seat: who was originally
     * assigned and, where applicable, who currently holds it.
     *
     * @return array<string, true>
     */
    public function representedIdentities(): array
    {
        $represented = [];

        foreach ($this->slots as $slot) {
            $represented[self::originalOf($slot)] = true;

            $delegate = self::delegateOf($slot);

            if ($delegate !== null) {
                $represented[$delegate] = true;
            }
        }

        return $represented;
    }

    public function hasVoted(ApprovalRequestStepApprover $slot): bool
    {
        return isset($this->voters[self::holderOf($slot)]);
    }

    /**
     * Whoever the seat currently belongs to: the delegate if there is one, the
     * original approver otherwise.
     */
    private static function holderOf(ApprovalRequestStepApprover $slot): string
    {
        return self::delegateOf($slot) ?? self::originalOf($slot);
    }

    private static function originalOf(ApprovalRequestStepApprover $slot): string
    {
        return Attributes::string($slot, 'approver_type').':'.Attributes::string($slot, 'approver_id');
    }

    private static function delegateOf(ApprovalRequestStepApprover $slot): ?string
    {
        $type = Attributes::nullableString($slot, 'delegated_to_type');
        $id = Attributes::nullableString($slot, 'delegated_to_id');

        return $type === null || $id === null ? null : $type.':'.$id;
    }

    private static function stringKey(Model $model): string
    {
        $key = $model->getKey();

        return is_scalar($key) ? (string) $key : '';
    }
}
