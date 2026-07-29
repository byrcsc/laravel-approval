<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Exceptions;

use Illuminate\Database\Eloquent\Model;

/**
 * A handover that would make a stage's approval requirement unreachable.
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
            'The [%s] receiving this assignment no longer exists.',
            $model::class,
        ));
    }

    public static function alreadyAssigned(Model $delegate): self
    {
        return new self(sprintf(
            '[%s] already has an assignment on this stage.',
            $delegate::class,
        ));
    }

    public static function assignmentIsDelegated(Model $delegate): self
    {
        return new self(sprintf(
            'This assignment is already delegated. Reassign it instead, or have [%s] delegate it again.',
            $delegate::class,
        ));
    }
}
