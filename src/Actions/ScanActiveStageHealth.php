<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Data\StageHealth;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Enums\StageStatus;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStage;
use Illuminate\Database\Eloquent\Collection;

final class ScanActiveStageHealth
{
    /**
     * @return Collection<int, ApprovalRequestStage>
     */
    public function __invoke(?int $limit = null, int $chunkSize = 200): Collection
    {
        $stranded = new Collection;

        $this->each(function (ApprovalRequestStage $stage, StageHealth $health) use ($stranded, $limit): bool {
            if ($health->stranded()) {
                $stranded->push($stage);
            }

            return $limit === null || $stranded->count() < max(1, $limit);
        }, $chunkSize);

        return $stranded;
    }

    /**
     * @param  callable(ApprovalRequestStage, StageHealth): bool  $callback
     */
    public function each(callable $callback, int $chunkSize = 200): void
    {
        $continue = true;

        ApprovalRequestStage::query()
            ->where('state', StageStatus::Active->value)
            ->whereHas('request', fn ($query) => $query->where('state', RequestState::Pending->value))
            ->with([
                'request',
                'assignments.approver',
                'assignments.delegatedTo',
                'actions',
            ])
            ->orderBy('id')
            ->chunkById(max(1, $chunkSize), function (Collection $stages) use ($callback, &$continue): bool {
                foreach ($stages as $stage) {
                    if (! $callback($stage, StageHealth::inspect($stage))) {
                        $continue = false;

                        return false;
                    }
                }

                return true;
            });
    }
}
