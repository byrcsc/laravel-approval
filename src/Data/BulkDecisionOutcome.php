<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Data;

use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use Throwable;

final readonly class BulkDecisionOutcome
{
    private function __construct(
        public ApprovalRequest $request,
        public ?ApprovalRequest $result,
        public ?Throwable $exception,
    ) {}

    public static function succeeded(ApprovalRequest $request, ApprovalRequest $result): self
    {
        return new self($request, $result, null);
    }

    public static function failed(ApprovalRequest $request, Throwable $exception): self
    {
        return new self($request, null, $exception);
    }

    public function successful(): bool
    {
        return $this->exception === null;
    }
}
