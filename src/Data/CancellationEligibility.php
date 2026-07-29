<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Data;

use ByRcsc\LaravelApproval\Enums\CancellationIneligibility;

final readonly class CancellationEligibility
{
    private function __construct(
        public bool $eligible,
        public ?CancellationIneligibility $reason,
    ) {}

    public static function granted(): self
    {
        return new self(true, null);
    }

    public static function denied(CancellationIneligibility $reason): self
    {
        return new self(false, $reason);
    }
}
