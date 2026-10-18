<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\ApprovalModels;
use ByRcsc\LaravelApproval\Exceptions\DelegationException;
use ByRcsc\LaravelApproval\Models\ApprovalDelegation;
use ByRcsc\LaravelApproval\Support\DelegationsTable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Hands the delegator's approvals to the delegate for a window of time.
 *
 * A delegator has at most one delegation covering any moment, so which delegate
 * an assignment goes to never depends on row order. When the window is open
 * now, the delegator's undecided assignments on active stages move at once.
 */
final class GrantDelegation
{
    public function __construct(private readonly SweepDelegations $sweep) {}

    public function __invoke(
        Model $delegator,
        Model $delegate,
        ?DateTimeInterface $startsAt = null,
        ?DateTimeInterface $endsAt = null,
        ?string $comment = null,
    ): ApprovalDelegation {
        if (! DelegationsTable::installed()) {
            throw DelegationException::notMigrated();
        }

        if ($delegator->is($delegate)) {
            throw DelegationException::toSelf($delegator);
        }

        foreach ([$delegator, $delegate] as $model) {
            if (! $model->exists) {
                throw DelegationException::doesNotExist($model);
            }
        }

        if ($endsAt !== null && $endsAt <= ($startsAt ?? Date::now())) {
            throw DelegationException::endsBeforeItStarts();
        }

        $delegation = DB::transaction(function () use ($delegator, $delegate, $startsAt, $endsAt, $comment): ApprovalDelegation {
            $overlapping = ApprovalModels::delegation()::query()
                ->current()
                ->fromDelegator($delegator)
                ->when($endsAt !== null, fn (Builder $query) => $query->where(
                    fn (Builder $start) => $start->whereNull('starts_at')->orWhere('starts_at', '<', $endsAt),
                ))
                ->when($startsAt !== null, fn (Builder $query) => $query->where(
                    fn (Builder $end) => $end->whereNull('ends_at')->orWhere('ends_at', '>', $startsAt),
                ))
                ->lockForUpdate()
                ->exists();

            if ($overlapping) {
                throw DelegationException::overlaps($delegator);
            }

            return ApprovalModels::delegation()::create([
                'delegator_type' => $delegator->getMorphClass(),
                'delegator_id' => $delegator->getKey(),
                'delegate_type' => $delegate->getMorphClass(),
                'delegate_id' => $delegate->getKey(),
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'comment' => $comment,
            ]);
        });

        if ($delegation->isActive()) {
            ($this->sweep)($delegator);
        }

        return $delegation;
    }
}
