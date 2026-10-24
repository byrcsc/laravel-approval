<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Data;

use ByRcsc\LaravelApproval\Definitions\WorkflowBuilder;
use ByRcsc\LaravelApproval\Definitions\WorkflowDefinition;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflow;
use Illuminate\Database\Eloquent\Model;

final readonly class AutoSubmission
{
    /**
     * @param  array<string, mixed>|null  $newAttributes
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public ApprovalWorkflow|WorkflowDefinition|WorkflowBuilder|string|null $workflow = null,
        public ?array $newAttributes = null,
        public ?Model $requester = null,
        public ?string $comment = null,
        public array $metadata = [],
    ) {}
}
