<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Data;

use ByRcsc\LaravelApproval\Exceptions\InvalidWorkflowException;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStep;
use ByRcsc\LaravelApproval\Support\Attributes;

final readonly class StepHealth
{
    /**
     * @return array{quorum: int, living: int, stranded: bool}
     */
    public static function inspect(ApprovalRequestStep $step): array
    {
        $ballot = Ballot::for($step);
        $living = count($ballot->holders());
        $quorum = $ballot->quorum();

        return [
            'quorum' => $quorum,
            'living' => $living,
            'stranded' => $living < $quorum,
        ];
    }

    public static function assertCarryable(ApprovalRequestStep $step, string $workflow): void
    {
        $health = self::inspect($step);

        if (! $health['stranded']) {
            if ($step->getAttributeValue('stranded_at') !== null) {
                $step->forceFill(['stranded_at' => null])->save();
            }

            return;
        }

        throw InvalidWorkflowException::livingQuorumUnreachable(
            $workflow,
            Attributes::int($step, 'sequence'),
            $health['quorum'],
            $health['living'],
        );
    }
}
