<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Data;

final readonly class WorkflowSyncResult
{
    /**
     * @param  list<string>  $created
     * @param  list<string>  $updated
     * @param  list<string>  $unchanged
     * @param  list<string>  $refused
     */
    public function __construct(
        public array $created,
        public array $updated,
        public array $unchanged,
        public array $refused,
        public bool $dryRun,
    ) {}

    public function successful(): bool
    {
        return $this->refused === [];
    }

    public function changed(): bool
    {
        return $this->created !== [] || $this->updated !== [];
    }
}
