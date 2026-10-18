<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Models;

use ByRcsc\LaravelApproval\Support\TableNames;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Date;

/**
 * A standing delegation: for a window of time, the delegator's assignments are
 * routed to the delegate.
 *
 * It does not touch assignments by itself. Granting it, revoking it, stage
 * activation, and `approval:delegations` move assignments to and from the
 * delegate, and each move is a line in the request's audit trail.
 */
class ApprovalDelegation extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return TableNames::for('delegations');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function delegator(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function delegate(): MorphTo
    {
        return $this->morphTo();
    }

    public function isActive(?DateTimeInterface $at = null): bool
    {
        $at ??= Date::now();
        $startsAt = $this->getAttributeValue('starts_at');
        $endsAt = $this->getAttributeValue('ends_at');

        return $this->getAttributeValue('revoked_at') === null
            && ($startsAt === null || $startsAt <= $at)
            && ($endsAt === null || $endsAt > $at);
    }

    /**
     * Delegations in force at the given moment, now by default.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query, ?DateTimeInterface $at = null): void
    {
        $at ??= Date::now();

        $query->whereNull('revoked_at')
            ->where(fn (Builder $start) => $start->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
            ->where(fn (Builder $end) => $end->whereNull('ends_at')->orWhere('ends_at', '>', $at));
    }

    /**
     * Delegations that are in force now or will be later: not revoked and not
     * yet ended.
     *
     * @param  Builder<self>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull('revoked_at')
            ->where(fn (Builder $end) => $end->whereNull('ends_at')->orWhere('ends_at', '>', Date::now()));
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeFromDelegator(Builder $query, Model $delegator): void
    {
        $query->where('delegator_type', $delegator->getMorphClass())
            ->where('delegator_id', $delegator->getKey());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
