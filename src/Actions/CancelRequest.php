<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Enums\StageStatus;
use ByRcsc\LaravelApproval\Events\ApprovalCancelled;
use ByRcsc\LaravelApproval\Exceptions\AlreadyDecidedException;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Support\Attributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

/**
 * Administrative cancellation after the host application authorizes the actor.
 */
final class CancelRequest
{
    public function __construct(private readonly RecordAction $recordAction) {}

    public function __invoke(
        ApprovalRequest $request,
        Model $actor,
        ?string $comment = null,
    ): ApprovalRequest {
        Gate::forUser($actor)->authorize('cancel', $request);

        return DB::transaction(function () use ($request, $actor, $comment): ApprovalRequest {
            $request->newQuery()->whereKey($request->getKey())->lockForUpdate()->get();
            $request->refresh();

            $state = Attributes::enum($request, 'state', RequestState::class) ?? RequestState::Pending;

            if (! in_array($state, [RequestState::Pending, RequestState::Conflicted], true)) {
                throw AlreadyDecidedException::requestIsClosed($request, $state);
            }

            $request->stages()
                ->whereIn('state', [StageStatus::Queued->value, StageStatus::Active->value])
                ->update(['state' => StageStatus::Skipped->value]);

            $request->forceFill([
                'state' => RequestState::Cancelled,
                'completed_at' => Date::now(),
            ])->save();

            ($this->recordAction)(
                request: $request,
                action: ActionType::Cancelled,
                actor: $actor,
                comment: $comment,
            );

            Event::dispatch(new ApprovalCancelled($request, $actor, $comment));

            return $request;
        });
    }
}
