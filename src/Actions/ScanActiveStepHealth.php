<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Data\StepHealth;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Enums\StepState;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStep;
use Illuminate\Database\Eloquent\Collection;

final class ScanActiveStepHealth
{
    /**
     * @return Collection<int, ApprovalRequestStep>
     */
    public function __invoke(?int $limit = null, int $chunkSize = 200): Collection
    {
        $stranded = new Collection;

        $this->each(function (ApprovalRequestStep $step, StepHealth $health) use ($stranded, $limit): bool {
            if ($health->stranded()) {
                $stranded->push($step);
            }

            return $limit === null || $stranded->count() < max(1, $limit);
        }, $chunkSize);

        return $stranded;
    }

    /**
     * @param  callable(ApprovalRequestStep, StepHealth): bool  $callback
     */
    public function each(callable $callback, int $chunkSize = 200): void
    {
        $continue = true;

        ApprovalRequestStep::query()
            ->where('state', StepState::Active->value)
            ->whereHas('request', fn ($query) => $query->where('state', RequestState::Pending->value))
            ->with([
                'request',
                'approverSlots.approver',
                'approverSlots.delegatedTo',
                'actions',
            ])
            ->orderBy('id')
            ->chunkById(max(1, $chunkSize), function (Collection $steps) use ($callback, &$continue): bool {
                foreach ($steps as $step) {
                    if (! $callback($step, StepHealth::inspect($step))) {
                        $continue = false;

                        return false;
                    }
                }

                return true;
            });
    }
}
