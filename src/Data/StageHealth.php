<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Data;

use ByRcsc\LaravelApproval\Exceptions\InvalidWorkflowException;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStage;
use ByRcsc\LaravelApproval\Support\Attributes;

/**
 * Whether a stage can still be completed by the people who are actually there.
 *
 * The approval rule counts assignments; `livingHolders` counts the assignments
 * whose holder still exists. A stage where the second is short of the first is
 * stranded: nobody can finish it, and nothing else in the engine will ever say so.
 */
final readonly class StageHealth
{
    private function __construct(
        public int $requiredApprovals,
        public int $livingHolders,
    ) {}

    public static function inspect(ApprovalRequestStage $stage): self
    {
        $progress = StageProgress::for($stage);

        return new self($progress->approvalsRequired, count($progress->assignmentHolders()));
    }

    public function stranded(): bool
    {
        return $this->livingHolders < $this->requiredApprovals;
    }

    /**
     * How many holders the stage is short. Zero when it can be completed.
     */
    public function deficit(): int
    {
        return max(0, $this->requiredApprovals - $this->livingHolders);
    }

    /**
     * The invariant, checked against where the stage started rather than
     * against perfection.
     *
     * Refusing every mutation that leaves a stage stranded also refuses the
     * mutations that are somebody repairing one: two dead assignments cannot be
     * replaced one at a time if the first replacement is rolled back for not
     * being the second as well. So a mutation is judged on whether it made
     * things worse, and a stage already short of holders stays open to the
     * delegations and reassignments that are trying to fix it.
     */
    public static function assertNotWorsened(ApprovalRequestStage $stage, string $workflow, self $before): void
    {
        $after = self::inspect($stage);

        if ($after->deficit() > $before->deficit()) {
            throw InvalidWorkflowException::livingHoldersBelowRequired(
                $workflow,
                Attributes::int($stage, 'sequence'),
                $after->requiredApprovals,
                $after->livingHolders,
            );
        }

        if (! $after->stranded() && $stage->getAttributeValue('stranded_at') !== null) {
            $stage->forceFill(['stranded_at' => null])->save();
        }
    }
}
