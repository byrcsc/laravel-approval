<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Enums\StepState;
use ByRcsc\LaravelApproval\Events\ApprovalSystemClosed;
use ByRcsc\LaravelApproval\Exceptions\AlreadyDecidedException;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Support\Attributes;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Internal lifecycle closure for model deletion and automated package work.
 */
final class SystemCloseRequest
{
    public function __construct(private readonly RecordAction $recordAction) {}

    public function __invoke(ApprovalRequest $request, ?string $reason = null): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $reason): ApprovalRequest {
            $request->newQuery()->whereKey($request->getKey())->lockForUpdate()->get();
            $request->refresh();

            $state = Attributes::enum($request, 'state', RequestState::class) ?? RequestState::Pending;

            if (! in_array($state, [RequestState::Pending, RequestState::Conflicted], true)) {
                throw AlreadyDecidedException::requestIsClosed($request, $state);
            }

            $request->steps()
                ->whereIn('state', [StepState::Queued->value, StepState::Active->value])
                ->update(['state' => StepState::Skipped->value]);

            $request->forceFill([
                'state' => RequestState::Cancelled,
                'completed_at' => Date::now(),
            ])->save();

            ($this->recordAction)(
                request: $request,
                action: ActionType::SystemClosed,
                comment: $reason,
            );

            Event::dispatch(new ApprovalSystemClosed($request, $reason));

            return $request;
        });
    }
}
