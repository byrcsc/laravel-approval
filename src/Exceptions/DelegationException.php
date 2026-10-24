<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Exceptions;

use Illuminate\Database\Eloquent\Model;

/**
 * A handover that would make a stage's approval requirement unreachable, or a
 * standing delegation that cannot be granted.
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

    public static function notMigrated(): self
    {
        return new self('Standing delegations need the delegations migration. Run `php artisan vendor:publish --tag="approval-migrations"` and then `php artisan migrate`.');
    }

    public static function endsBeforeItStarts(): self
    {
        return new self('A delegation cannot end before it starts.');
    }

    public static function overlaps(Model $delegator): self
    {
        return new self(sprintf(
            '[%s] already has a delegation covering part of this window. Revoke it first.',
            $delegator::class,
        ));
    }

    public static function alreadyRevoked(): self
    {
        return new self('This delegation has already been revoked.');
    }
}
