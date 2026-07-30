<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Data;

use ByRcsc\LaravelApproval\Enums\WithdrawalIneligibility;

final readonly class WithdrawalEligibility
{
    private function __construct(
        public bool $eligible,
        public ?WithdrawalIneligibility $reason,
    ) {}

    public static function granted(): self
    {
        return new self(true, null);
    }

    public static function denied(WithdrawalIneligibility $reason): self
    {
        return new self(false, $reason);
    }
}
