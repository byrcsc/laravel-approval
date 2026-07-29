<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Data;

final readonly class BulkDecisionResult
{
    /**
     * @param  list<BulkDecisionOutcome>  $outcomes
     */
    public function __construct(public array $outcomes) {}

    /**
     * @return list<BulkDecisionOutcome>
     */
    public function successful(): array
    {
        return array_values(array_filter(
            $this->outcomes,
            fn (BulkDecisionOutcome $outcome): bool => $outcome->successful(),
        ));
    }

    /**
     * @return list<BulkDecisionOutcome>
     */
    public function failed(): array
    {
        return array_values(array_filter(
            $this->outcomes,
            fn (BulkDecisionOutcome $outcome): bool => ! $outcome->successful(),
        ));
    }
}
