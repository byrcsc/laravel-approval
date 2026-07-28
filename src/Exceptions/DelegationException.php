<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Exceptions;

use Illuminate\Database\Eloquent\Model;

/**
 * A handover that would break the level it happens on.
 *
 * Both cases here are really the same case: a quorum counts seats, so anything
 * that quietly turns two seats into one holder turns a two-of-three into a
 * level nobody can carry. Better to refuse the handover than to strand the
 * request.
 */
final class DelegationException extends ApprovalException
{
    public static function toSelf(Model $actor): self
    {
        return new self(sprintf('[%s] cannot delegate their approval to themselves.', $actor::class));
    }

    public static function doesNotExist(Model $model): self
    {
        return new self(sprintf(
            'The [%s] receiving this seat no longer exists. A seat can only be handed to somebody who is still there to use it.',
            $model::class,
        ));
    }

    public static function alreadySeated(Model $delegate): self
    {
        return new self(sprintf(
            '[%s] already holds a seat on this level. Delegating a second one to them would leave the level a seat short of its quorum forever.',
            $delegate::class,
        ));
    }

    public static function seatIsTaken(Model $delegate): self
    {
        return new self(sprintf(
            'This seat is already delegated. Reassign it instead, or have [%s] hand it back first.',
            $delegate::class,
        ));
    }
}
