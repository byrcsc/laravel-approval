<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\ApprovalModels;
use ByRcsc\LaravelApproval\Data\Draft;
use ByRcsc\LaravelApproval\Enums\ConflictResolution;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Exceptions\ApprovalInProgressException;
use ByRcsc\LaravelApproval\Exceptions\DraftConflictException;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Support\Attributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Administrative resolution of a conflicted request.
 *
 * Reapplying writes the draft over the record's current values. It is recorded
 * as a forced apply, attributed to the operator, with the attributes that had
 * drifted kept in the audit history. Discarding cancels the request and leaves
 * the record unchanged; the same edit can be raised again as a new request
 * against current values.
 *
 * Neither resolution requires re-approval. The stages already approved the
 * change itself; what the conflict raised was whether it still applied to the
 * record, which is the question this answers.
 */
final class ResolveConflict
{
    public function __construct(
        private readonly ApplyDraft $applyDraft,
        private readonly CancelRequest $cancelRequest,
    ) {}

    public function __invoke(
        ApprovalRequest $request,
        ConflictResolution $resolution,
        ?Model $actor = null,
        ?string $comment = null,
    ): ApprovalRequest {
        return DB::transaction(function () use ($request, $resolution, $actor, $comment): ApprovalRequest {
            $request->newQuery()->whereKey($request->getKey())->lockForUpdate()->get();
            $request->refresh();

            $state = Attributes::enum($request, 'state', RequestState::class) ?? RequestState::Pending;

            if ($state !== RequestState::Conflicted) {
                throw DraftConflictException::notConflicted($request, $state);
            }

            return match ($resolution) {
                ConflictResolution::Reapply => $this->reapply($request, $actor),
                ConflictResolution::Discard => $this->discard($request, $actor, $comment),
            };
        });
    }

    private function reapply(ApprovalRequest $request, ?Model $actor): ApprovalRequest
    {
        if (Draft::fromRequest($request) === null) {
            throw DraftConflictException::nothingToApply($request);
        }

        $approvable = $request->approvable;

        if (! $approvable instanceof Model) {
            throw DraftConflictException::approvableIsGone($request);
        }

        $approvable->newQuery()->whereKey($approvable->getKey())->lockForUpdate()->get();

        /** @var ApprovalRequest|null $other */
        $other = ApprovalModels::request()::query()
            ->forApprovable($approvable)
            ->unresolved()
            ->whereKeyNot($request->getKey())
            ->first();

        if ($other !== null) {
            throw ApprovalInProgressException::forApprovable($approvable, $other);
        }

        return ($this->applyDraft)($request, $actor, force: true);
    }

    /**
     * Discarding is recorded as an attributable administrative cancellation.
     */
    private function discard(ApprovalRequest $request, ?Model $actor, ?string $comment): ApprovalRequest
    {
        if (! $actor instanceof Model) {
            throw new \InvalidArgumentException('An actor is required to discard a conflicted request.');
        }

        return ($this->cancelRequest)($request, $actor, $comment ?? 'Conflicted draft discarded.');
    }
}
