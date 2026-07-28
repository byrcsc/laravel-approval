<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Events;

use ByRcsc\LaravelApproval\Data\Draft;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use Illuminate\Database\Eloquent\Model;

/**
 * The draft is on the record. `$approvable` is already saved when this fires,
 * so a listener reads the new values, not the old ones.
 */
final class DraftApplied extends ApprovalEvent
{
    public function __construct(
        ApprovalRequest $request,
        public readonly Model $approvable,
        public readonly Draft $draft,
    ) {
        parent::__construct($request);
    }
}
