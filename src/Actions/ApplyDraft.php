<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Data\Draft;
use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Events\ApprovalApproved;
use ByRcsc\LaravelApproval\Events\DraftApplied;
use ByRcsc\LaravelApproval\Events\DraftConflicted;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Support\ApprovalGuard;
use ByRcsc\LaravelApproval\Support\DraftDiffer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;

/**
 * Closes an approved request by writing its draft onto the record - or by
 * refusing to, and saying why.
 *
 * The stale check is the whole point. An approval chain can take days, and
 * nothing stops somebody editing the record while it runs. Applying a
 * three-day-old draft over those edits would silently undo them, so a record
 * that has moved on any of the drafted attributes puts the request into
 * `conflicted` and leaves the record alone.
 *
 * That is a state, not an exception: the last approver did nothing wrong, and
 * failing their click would be an odd way to tell them so. The request waits
 * for somebody to decide, through {@see ResolveConflict}.
 *
 * Runs inside the caller's transaction. Whoever calls it has the request row
 * locked already; this takes the record's row too, in that order.
 */
final class ApplyDraft
{
    public function __construct(private readonly RecordAction $recordAction) {}

    /**
     * @param  bool  $force  apply over drift rather than conflicting on it - the reapply path, never the automatic one
     */
    public function __invoke(ApprovalRequest $request, ?Model $actor = null, bool $force = false): ApprovalRequest
    {
        $draft = Draft::fromRequest($request);

        if ($draft === null) {
            $this->complete($request);
            Event::dispatch(new ApprovalApproved($request));

            return $request;
        }

        $approvable = $request->approvable;

        if (! $approvable instanceof Model) {
            return $this->conflict($request, null, []);
        }

        $this->lock($approvable);

        $drift = DraftDiffer::drift($approvable, $draft->original);

        if ($drift !== [] && ! $force) {
            return $this->conflict($request, $approvable, $drift);
        }

        // Guarding is the host model's rule about mass assignment from a form.
        // This is not a form: the attributes were checked against
        // `approvableAttributes()` at submit and then approved by people.
        ApprovalGuard::without($approvable, function () use ($approvable, $draft): void {
            $approvable->forceFill($draft->new)->save();
        });

        ($this->recordAction)(
            request: $request,
            action: ActionType::Applied,
            actor: $actor,
            metadata: array_filter([
                'changes' => $draft->changes(),
                'forced' => $force ? $drift : null,
            ]),
        );

        $this->complete($request);

        Event::dispatch(new DraftApplied($request, $approvable, $draft));
        Event::dispatch(new ApprovalApproved($request));

        return $request;
    }

    private function complete(ApprovalRequest $request): ApprovalRequest
    {
        $request->forceFill([
            'state' => RequestState::Approved,
            'completed_at' => Date::now(),
        ])->save();

        return $request;
    }

    /**
     * @param  list<string>  $drift
     */
    private function conflict(ApprovalRequest $request, ?Model $approvable, array $drift): ApprovalRequest
    {
        $request->forceFill([
            'state' => RequestState::Conflicted,
            'completed_at' => null,
        ])->save();

        ($this->recordAction)(
            request: $request,
            action: ActionType::Conflicted,
            metadata: $approvable === null
                ? ['reason' => 'missing_record']
                : ['reason' => 'stale_attributes', 'attributes' => $drift],
        );

        Event::dispatch(new DraftConflicted($request, $approvable, $drift));

        return $request;
    }

    /**
     * Locks the record and reloads it, so the comparison reads what the
     * database holds rather than whatever the caller had in memory.
     */
    private function lock(Model $approvable): void
    {
        $approvable->newQuery()->whereKey($approvable->getKey())->lockForUpdate()->get();

        $approvable->refresh();
    }
}
