<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Events;

use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStep;
use Illuminate\Database\Eloquent\Model;

/**
 * The configured rejection policy has ended the whole request. Levels that
 * never ran are skipped rather than rejected. Resubmitting starts a new request
 * at level one.
 *
 * `$actor` is null when the clock rejected it rather than a person - an
 * `auto_reject` level whose deadline passed.
 */
final class ApprovalRejected extends ApprovalEvent
{
    public function __construct(
        ApprovalRequest $request,
        public readonly ApprovalRequestStep $step,
        public readonly ?Model $actor,
        public readonly string $comment,
    ) {
        parent::__construct($request);
    }
}
