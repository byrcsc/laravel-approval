<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Models\ApprovalAction;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStep;
use Illuminate\Database\Eloquent\Model;

/**
 * Appends one line to the audit trail.
 *
 * Every other action in this namespace calls this when it makes an auditable
 * decision or administrative change. Nothing else in the package writes to
 * that table.
 */
final class RecordAction
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __invoke(
        ApprovalRequest $request,
        ActionType $action,
        ?Model $actor = null,
        ?ApprovalRequestStep $step = null,
        ?string $comment = null,
        array $metadata = [],
    ): ApprovalAction {
        return ApprovalAction::create([
            'request_id' => $request->getKey(),
            'step_id' => $step?->getKey(),
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'comment' => $comment,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }
}
