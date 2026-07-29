<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Events;

use ByRcsc\LaravelApproval\Actions\ResyncRequestApprovers;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use Illuminate\Database\Eloquent\Model;

/**
 * A live request's approver snapshot was deliberately rebuilt from the current
 * workflow. The escape hatch, not the norm - snapshots go stale by design.
 *
 * `$changes` is keyed by step sequence, each holding `added` and `removed`
 * lists of morph keys.
 *
 * @see ResyncRequestApprovers
 */
final class ApproversResynced extends ApprovalEvent
{
    /**
     * @param  array<int, array{added: list<string>, removed: list<string>}>  $changes
     */
    public function __construct(
        ApprovalRequest $request,
        public readonly array $changes,
        public readonly ?Model $actor = null,
    ) {
        parent::__construct($request);
    }
}
