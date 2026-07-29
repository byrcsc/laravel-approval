<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Contracts\ApproverResolver;
use ByRcsc\LaravelApproval\Data\ApproverSlot;
use ByRcsc\LaravelApproval\Data\LevelSnapshot;
use ByRcsc\LaravelApproval\Enums\ApproverSource;
use ByRcsc\LaravelApproval\Exceptions\InvalidWorkflowException;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflowLevel;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflowLevelApprover;
use ByRcsc\LaravelApproval\Support\Attributes;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Turns one level's approver rules into the seats they describe, right now.
 *
 * Submitting is the usual caller; resyncing a live request is the other, which
 * is the whole reason this is not buried inside {@see SubmitForApproval}.
 * Asking the same question twice has to get the same kind of answer, or a
 * resync would quietly build a different level than a submission would.
 *
 * Duplicates collapse: two rules reaching the same person describe one seat.
 * Definition order wins, so a concrete approver listed before a resolver that
 * also yields them keeps its `assigned` provenance.
 */
final class ResolveLevelApprovers
{
    public function __construct(private readonly Container $container) {}

    /**
     * @return list<ApproverSlot>
     */
    public function __invoke(
        ApprovalWorkflowLevel $level,
        LevelSnapshot $snapshot,
        Model $approvable,
        Model $submitter,
        string $slug,
    ): array {
        $slots = [];
        $seen = [];

        foreach ($this->entries($level) as $entry) {
            foreach ($this->expand($slug, $entry, $snapshot, $approvable, $submitter) as $slot) {
                if (isset($seen[$slot->key()])) {
                    continue;
                }

                $seen[$slot->key()] = true;
                $slots[] = $slot;
            }
        }

        return $slots;
    }

    /**
     * @return iterable<int, ApprovalWorkflowLevelApprover>
     */
    private function entries(ApprovalWorkflowLevel $level): iterable
    {
        // Uses the already-loaded relation when the caller eager-loaded it,
        // and reads in definition order when it did not.
        if ($level->relationLoaded('approvers')) {
            /** @var iterable<int, ApprovalWorkflowLevelApprover> */
            return $level->approvers;
        }

        /** @var iterable<int, ApprovalWorkflowLevelApprover> */
        return $level->approvers()->orderBy('id')->get();
    }

    /**
     * One approver entry, which is either a record or a rule that produces
     * records. Never both, never neither - no portable database constraint says
     * so, so this does.
     *
     * @return list<ApproverSlot>
     */
    private function expand(
        string $slug,
        ApprovalWorkflowLevelApprover $entry,
        LevelSnapshot $snapshot,
        Model $approvable,
        Model $submitter,
    ): array {
        $type = Attributes::nullableString($entry, 'approver_type');
        $id = $entry->getAttributeValue('approver_id');
        $resolver = Attributes::nullableString($entry, 'resolver');

        $isConcrete = $type !== null && $type !== '' && (is_int($id) || is_string($id));
        $isResolved = $resolver !== null && $resolver !== '';

        if ($isConcrete && $isResolved) {
            throw InvalidWorkflowException::ambiguousApprover($slug, $snapshot->sequence);
        }

        if ($isConcrete) {
            /** @var string $type */
            /** @var int|string $id */
            return [$this->concreteApprover($slug, $snapshot->sequence, $type, $id)];
        }

        if (! $isResolved) {
            throw InvalidWorkflowException::emptyApprover($slug, $snapshot->sequence);
        }

        /** @var string $resolver */
        return $this->runResolver($slug, $resolver, $entry, $snapshot, $approvable, $submitter);
    }

    private function concreteApprover(string $slug, int $sequence, string $type, int|string $id): ApproverSlot
    {
        $class = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            throw InvalidWorkflowException::concreteApproverMissing($slug, $sequence, $type, $id);
        }

        $approver = new $class;

        if (! $approver->newQuery()->whereKey($id)->exists()) {
            throw InvalidWorkflowException::concreteApproverMissing($slug, $sequence, $type, $id);
        }

        return new ApproverSlot($type, $id, ApproverSource::Assigned);
    }

    /**
     * @return list<ApproverSlot>
     */
    private function runResolver(
        string $slug,
        string $resolver,
        ApprovalWorkflowLevelApprover $entry,
        LevelSnapshot $snapshot,
        Model $approvable,
        Model $submitter,
    ): array {
        if (! class_exists($resolver)) {
            throw InvalidWorkflowException::unknownResolver($slug, $snapshot->sequence, $resolver);
        }

        if (! is_subclass_of($resolver, ApproverResolver::class)) {
            throw InvalidWorkflowException::notAResolver($slug, $snapshot->sequence, $resolver);
        }

        /** @var ApproverResolver $instance */
        $instance = $this->container->make($resolver);

        $slots = [];

        /** @var mixed $approver */
        foreach ($instance->resolve($snapshot, $approvable, $submitter, Attributes::object($entry, 'resolver_config')) as $approver) {
            if (! $approver instanceof Model) {
                throw InvalidWorkflowException::resolverReturnedNonModel($resolver);
            }

            if (! $approver->exists) {
                throw InvalidWorkflowException::resolverReturnedUnsavedModel($resolver);
            }

            $slots[] = ApproverSlot::forModel($approver, ApproverSource::Resolved, $resolver);
        }

        return $slots;
    }

    /**
     * The eager-load constraint the callers share, so approver rows arrive in
     * definition order.
     *
     * @return array<string, Closure(Relation<*, *, *>): mixed>
     */
    public static function ordered(): array
    {
        return ['approvers' => static fn (Relation $query): mixed => $query->orderBy('id')];
    }
}
