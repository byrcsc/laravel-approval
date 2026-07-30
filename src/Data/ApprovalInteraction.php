<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Data;

use ByRcsc\LaravelApproval\Enums\Verdict;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflow;
use Illuminate\Database\Eloquent\Model;

/**
 * One approval operation recorded by the approval fake.
 *
 * `type` names the operation, `target` is the request, approvable model, or list
 * of requests it acted on, and `actor` is whoever performed it. `recipient`
 * carries the approver an assignment moved to, for delegation and reassignment.
 * Operation-specific input - a conflict resolution, a revised draft, attachment
 * details - is passed through in `metadata`.
 */
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
        public ?Model $recipient = null,
    ) {}
}
