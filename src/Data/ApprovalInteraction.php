<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Data;

use ByRcsc\LaravelApproval\Definitions\WorkflowBuilder;
use ByRcsc\LaravelApproval\Definitions\WorkflowDefinition;
use ByRcsc\LaravelApproval\Enums\InteractionType;
use ByRcsc\LaravelApproval\Enums\Verdict;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflow;
use Illuminate\Database\Eloquent\Model;

/**
 * One approval operation recorded by the approval fake.
 *
 * `type` names the operation and `actor` is whoever performed it. `target` is the
 * request, approvable model, or list of requests the operation acted on, and is
 * null for the operations that act on no single subject: overdue processing and
 * definition synchronization. `recipient` carries the approver an assignment
 * moved to, for delegation and reassignment. Operation-specific input - a
 * conflict resolution, a revised draft, attachment details - is passed through
 * in `metadata`.
 */
final readonly class ApprovalInteraction
{
    /**
     * @param  Model|ApprovalRequest|list<ApprovalRequest>|null  $target
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public InteractionType $type,
        public Model|ApprovalRequest|array|null $target = null,
        public ?Model $actor = null,
        public ApprovalWorkflow|WorkflowDefinition|WorkflowBuilder|string|null $workflow = null,
        public ?Verdict $verdict = null,
        public ?string $comment = null,
        public array $metadata = [],
        public ?Model $recipient = null,
    ) {}
}
