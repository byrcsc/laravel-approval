<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Data;

use ByRcsc\LaravelApproval\Exceptions\InvalidWorkflowException;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStep;
use ByRcsc\LaravelApproval\Support\Attributes;

/**
 * Whether a level can still be carried by the people who are actually there.
 *
 * Quorum counts seats; `living` counts the seats whose holder still exists.
 * A level where the second is short of the first is stranded: nobody can
 * finish it, and nothing else in the engine will ever say so.
 */
final readonly class StepHealth
{
    private function __construct(
        public int $quorum,
        public int $living,
    ) {}

    public static function inspect(ApprovalRequestStep $step): self
    {
        $progress = StageProgress::for($step);

        return new self($progress->approvalsRequired, count($progress->assignmentHolders()));
    }

    public function stranded(): bool
    {
        return $this->living < $this->quorum;
    }

    /**
     * How many holders the level is short. Zero when it can be carried.
     */
    public function deficit(): int
    {
        return max(0, $this->quorum - $this->living);
    }

    /**
     * The invariant, checked against where the level started rather than
     * against perfection.
     *
     * Refusing every mutation that leaves a level stranded also refuses the
     * mutations that are somebody repairing one: two dead seats cannot be
     * replaced one at a time if the first replacement is rolled back for not
     * being the second as well. So a mutation is judged on whether it made
     * things worse, and a level already short of holders stays open to the
     * delegations and reassignments that are trying to fix it.
     */
    public static function assertNotWorsened(ApprovalRequestStep $step, string $workflow, self $before): void
    {
        $after = self::inspect($step);

        if ($after->deficit() > $before->deficit()) {
            throw InvalidWorkflowException::livingQuorumUnreachable(
                $workflow,
                Attributes::int($step, 'sequence'),
                $after->quorum,
                $after->living,
            );
        }

        if (! $after->stranded() && $step->getAttributeValue('stranded_at') !== null) {
            $step->forceFill(['stranded_at' => null])->save();
        }
    }
}
