<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Events;

use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStage;
use Illuminate\Database\Eloquent\Model;

/**
 * The configured rejection policy has ended the whole request. Stages that
 * never ran are skipped rather than rejected. Resubmitting starts a new request
 * at stage one.
 *
 * `$actor` is null when the clock rejected it rather than a person - an
 * `auto_reject` stage whose deadline passed.
 */
final class ApprovalRejected extends ApprovalEvent
{
    public function __construct(
        ApprovalRequest $request,
        public readonly ApprovalRequestStage $stage,
        public readonly ?Model $actor,
        public readonly string $comment,
    ) {
        parent::__construct($request);
    }
}
