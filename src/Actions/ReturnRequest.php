<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Data\ApprovalDecision;
use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Enums\StageStatus;
use ByRcsc\LaravelApproval\Enums\Verdict;
use ByRcsc\LaravelApproval\Events\ApprovalReturned;
use ByRcsc\LaravelApproval\Exceptions\CommentRequiredException;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

final class ReturnRequest
{
    public function __construct(
        private readonly AuthorizeDecision $authorize,
        private readonly RecordAction $recordAction,
    ) {}

    public function __invoke(ApprovalRequest $request, ApprovalDecision $decision): ApprovalRequest
    {
        $comment = $decision->comment();

        if ($decision->verdict !== Verdict::Return || $comment === null) {
            throw CommentRequiredException::toReturn();
        }

        return DB::transaction(function () use ($request, $decision, $comment): ApprovalRequest {
            $request->newQuery()->whereKey($request->getKey())->lockForUpdate()->get();
            $request->refresh();

            $context = ($this->authorize)($request, $decision->actor);

            ($this->recordAction)(
                request: $request,
                action: ActionType::Returned,
                actor: $decision->actor,
                stage: $context->stage,
                comment: $comment,
                metadata: $decision->metadata,
            );

            $context->stage->forceFill(['state' => StageStatus::Returned])->save();

            $request->stages()
                ->whereKeyNot($context->stage->getKey())
                ->whereIn('state', [StageStatus::Queued->value, StageStatus::Active->value])
                ->update(['state' => StageStatus::Skipped->value]);

            $request->forceFill([
                'state' => RequestState::Returned,
                'completed_at' => Date::now(),
            ])->save();

            Event::dispatch(new ApprovalReturned($request, $context->stage, $decision->actor, $comment));

            return $request;
        });
    }
}
