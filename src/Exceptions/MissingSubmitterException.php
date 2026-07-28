<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Exceptions;

use Illuminate\Database\Eloquent\Model;

/**
 * Every request is submitted by somebody. Queue workers and console commands
 * have no authenticated user, which is exactly when this fires - pass the
 * submitter explicitly, or teach the model to name one.
 */
final class MissingSubmitterException extends ApprovalException
{
    public static function forApprovable(Model $approvable): self
    {
        return new self(sprintf(
            'No submitter could be determined for [%s]. Pass one to submitForApproval(), return one from approvalSubmitter(), or run this where a user is authenticated.',
            $approvable::class,
        ));
    }
}
