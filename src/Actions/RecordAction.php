<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Models\ApprovalAction;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStep;
use ByRcsc\LaravelApproval\Support\ActionHash;
use ByRcsc\LaravelApproval\Support\Attributes;
use ByRcsc\LaravelApproval\Support\AuditHashPayload;
use ByRcsc\LaravelApproval\Support\Labels;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Appends one line to the audit trail.
 *
 * Every other action in this namespace calls this when it makes an auditable
 * decision or administrative change. Nothing else in the package writes to
 * that table - which is a real invariant, not a convention: each line carries
 * a hash chaining it to the one before, and this is the only place the chain
 * is maintained. A writer that bypasses this breaks `approval:verify` for the
 * request it touches, and the arch test pins the single write path.
 *
 * The actor is recorded twice: as a morph key, and as `actor_label` - words
 * captured now and never refreshed, so the trail still names the person after
 * their account is renamed or deleted.
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
        return DB::transaction(function () use ($request, $action, $actor, $step, $comment, $metadata): ApprovalAction {
            $lockedRequest = ApprovalRequest::query()
                ->whereKey($request->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $createdAt = Date::now();
            $previous = Attributes::nullableString($lockedRequest, 'audit_head_hash');
            $actorLabel = $actor === null ? null : Labels::for($actor);
            $actorId = $actor?->getKey();
            $actorId = is_scalar($actorId) ? (string) $actorId : null;
            $stepId = $step?->getKey();

            $hash = ActionHash::compute(new AuditHashPayload(
                requestUlid: Attributes::string($lockedRequest, 'ulid'),
                previousHash: $previous,
                action: $action->value,
                actorType: $actor?->getMorphClass(),
                actorId: $actorId,
                actorLabel: $actorLabel,
                stepId: is_int($stepId) ? $stepId : null,
                comment: $comment,
                metadata: $metadata === [] ? null : $metadata,
                createdAt: $createdAt->format('Y-m-d H:i:s'),
            ));

            $record = ApprovalAction::create([
                'request_id' => $lockedRequest->getKey(),
                'step_id' => $step?->getKey(),
                'actor_type' => $actor?->getMorphClass(),
                'actor_id' => $actor?->getKey(),
                'actor_label' => $actorLabel,
                'action' => $action,
                'comment' => $comment,
                'metadata' => $metadata === [] ? null : $metadata,
                'hash' => $hash,
                'previous_hash' => $previous,
                'created_at' => $createdAt,
            ]);

            $lockedRequest->forceFill(['audit_head_hash' => $hash])->save();

            return $record;
        });
    }
}
