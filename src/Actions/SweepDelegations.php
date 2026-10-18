<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\ApprovalModels;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Enums\StageStatus;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStage;
use ByRcsc\LaravelApproval\Support\Attributes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Finds the active stages whose assignments are out of line with the standing
 * delegations, and applies them one request at a time.
 *
 * Granting and revoking a delegation run this for the delegator straight away.
 * A delegation that starts or ends later needs `approval:delegations` on a
 * schedule, because nothing else notices the clock passing a window's edge.
 */
final class SweepDelegations
{
    public function __construct(private readonly ApplyDelegations $applyDelegations) {}

    /**
     * @return int how many assignments moved
     */
    public function __invoke(?Model $delegator = null): int
    {
        $moved = 0;

        foreach ($this->stagesNeedingWork($delegator)->get() as $stage) {
            $moved += DB::transaction(function () use ($stage): int {
                $request = $stage->request;

                if ($request === null) {
                    return 0;
                }

                $request->newQuery()->whereKey($request->getKey())->lockForUpdate()->get();
                $stage->refresh();

                if (Attributes::enum($stage, 'state', StageStatus::class) !== StageStatus::Active) {
                    return 0;
                }

                return ($this->applyDelegations)($stage);
            });
        }

        return $moved;
    }

    /**
     * @return Builder<ApprovalRequestStage>
     */
    private function stagesNeedingWork(?Model $delegator): Builder
    {
        $delegations = ApprovalModels::delegation();
        $assignments = (new (ApprovalModels::assignment()))->getTable();
        $delegationTable = (new $delegations)->getTable();

        return ApprovalModels::requestStage()::query()
            ->where('state', StageStatus::Active->value)
            ->whereHas('request', fn (Builder $request) => $request->where('state', RequestState::Pending->value))
            ->whereHas('assignments', function (Builder $assignment) use ($delegator, $delegations, $assignments, $delegationTable): void {
                if ($delegator !== null) {
                    $assignment->where('approver_type', $delegator->getMorphClass())
                        ->where('approver_id', $delegator->getKey());
                }

                $assignment->where(fn (Builder $either) => $either
                    // Routed by a window that has since closed.
                    ->where(fn (Builder $ended) => $ended
                        ->whereNotNull('delegation_id')
                        ->whereDoesntHave('delegation', fn (Builder $delegation) => $delegation->active()))
                    // Still with an approver who has a window open.
                    ->orWhere(fn (Builder $due) => $due
                        ->whereNull('delegated_to_id')
                        ->whereExists($delegations::query()
                            ->active()
                            ->whereColumn("{$delegationTable}.delegator_type", "{$assignments}.approver_type")
                            ->whereColumn("{$delegationTable}.delegator_id", "{$assignments}.approver_id")
                            ->toBase())));
            })
            ->orderBy('id');
    }
}
