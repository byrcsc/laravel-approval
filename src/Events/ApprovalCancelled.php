<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Events;

use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use Illuminate\Database\Eloquent\Model;

/**
 * Withdrawn rather than decided. `$actor` is null when the system closed it -
 * an escalation strategy, or a cleanup command.
 */
final class ApprovalCancelled extends ApprovalEvent
{
    public function __construct(
        ApprovalRequest $request,
        public readonly ?Model $actor = null,
        public readonly ?string $comment = null,
    ) {
        parent::__construct($request);
    }
}
