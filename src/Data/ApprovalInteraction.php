<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Data;

use ByRcsc\LaravelApproval\Enums\Verdict;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflow;
use Illuminate\Database\Eloquent\Model;

final readonly class ApprovalInteraction
{
    /**
     * @param  Model|ApprovalRequest|list<ApprovalRequest>  $target
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $type,
        public Model|ApprovalRequest|array $target,
        public ?Model $actor = null,
        public ApprovalWorkflow|string|null $workflow = null,
        public ?Verdict $verdict = null,
        public ?string $comment = null,
        public array $metadata = [],
    ) {}
}
