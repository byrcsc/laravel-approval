<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Contracts\ApproverResolver;
use ByRcsc\LaravelApproval\Data\AssignmentSnapshot;
use ByRcsc\LaravelApproval\Data\StageSnapshot;
use ByRcsc\LaravelApproval\Enums\ApproverSource;
use ByRcsc\LaravelApproval\Exceptions\InvalidWorkflowException;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflowStage;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflowStageApprover;
use ByRcsc\LaravelApproval\Support\Attributes;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Turns one stage's approver rules into the assignments they describe, right now.
 *
 * Submitting is the usual caller; resyncing a live request is the other, which
 * is the whole reason this is not buried inside {@see SubmitForApproval}.
 * Asking the same question twice has to get the same kind of answer, or a
 * resync would quietly build a different stage than a submission would.
 *
 * Duplicates collapse: two rules reaching the same person describe one assignment.
 * Definition order wins, so a concrete approver listed before a resolver that
 * also yields them keeps its `assigned` provenance.
 */
final class ResolveStageApprovers
{
    public function __construct(private readonly Container $container) {}

    /**
     * @return list<AssignmentSnapshot>
     */
    public function __invoke(
        ApprovalWorkflowStage $stage,
        StageSnapshot $snapshot,
        Model $approvable,
        Model $submitter,
        string $slug,
    ): array {
        $assignments = [];
        $seen = [];

        foreach ($this->entries($stage) as $entry) {
            foreach ($this->expand($slug, $entry, $snapshot, $approvable, $submitter) as $assignment) {
                if (isset($seen[$assignment->key()])) {
                    continue;
                }

                $seen[$assignment->key()] = true;
                $assignments[] = $assignment;
            }
        }

        return $assignments;
    }

    /**
     * @return iterable<int, ApprovalWorkflowStageApprover>
     */
    private function entries(ApprovalWorkflowStage $stage): iterable
    {
        // Uses the already-loaded relation when the caller eager-loaded it,
        // and reads in definition order when it did not.
        if ($stage->relationLoaded('approvers')) {
            /** @var iterable<int, ApprovalWorkflowStageApprover> */
            return $stage->approvers;
        }

        /** @var iterable<int, ApprovalWorkflowStageApprover> */
        return $stage->approvers()->orderBy('id')->get();
    }

    /**
     * One approver entry, which is either a record or a rule that produces
     * records. Never both, never neither - no portable database constraint says
     * so, so this does.
     *
     * @return list<AssignmentSnapshot>
     */
    private function expand(
        string $slug,
        ApprovalWorkflowStageApprover $entry,
        StageSnapshot $snapshot,
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

    private function concreteApprover(string $slug, int $sequence, string $type, int|string $id): AssignmentSnapshot
    {
        $class = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            throw InvalidWorkflowException::concreteApproverMissing($slug, $sequence, $type, $id);
        }

        $approver = new $class;

        if (! $approver->newQuery()->whereKey($id)->exists()) {
            throw InvalidWorkflowException::concreteApproverMissing($slug, $sequence, $type, $id);
        }

        return new AssignmentSnapshot($type, $id, ApproverSource::Assigned);
    }

    /**
     * @return list<AssignmentSnapshot>
     */
    private function runResolver(
        string $slug,
        string $resolver,
        ApprovalWorkflowStageApprover $entry,
        StageSnapshot $snapshot,
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

        $assignments = [];

        /** @var mixed $approver */
        foreach ($instance->resolve($snapshot, $approvable, $submitter, Attributes::object($entry, 'resolver_config')) as $approver) {
            if (! $approver instanceof Model) {
                throw InvalidWorkflowException::resolverReturnedNonModel($resolver);
            }

            if (! $approver->exists) {
                throw InvalidWorkflowException::resolverReturnedUnsavedModel($resolver);
            }

            $assignments[] = AssignmentSnapshot::forModel($approver, ApproverSource::Resolved, $resolver);
        }

        return $assignments;
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
