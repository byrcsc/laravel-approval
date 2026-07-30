<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

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
 * The way out of a conflicted request, once a person has looked at it.
 *
 * Reapplying writes the draft over whatever the record now holds, which is a
 * decision somebody has to own - so it is recorded as a forced apply, with the
 * attributes that had drifted kept in the audit trail. Discarding cancels the
 * request and leaves the record as it is; the edit can be raised again as a
 * fresh request against current values.
 *
 * No re-approval is required for either. The stages already approved this
 * change; what was in doubt was whether it still fit the record, and that is
 * the question being answered here.
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
        $other = ApprovalRequest::query()
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
