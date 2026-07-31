<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Exceptions;

use Illuminate\Database\Eloquent\Model;

/**
 * Raised when no requester can be resolved for a submission.
 *
 * Every request records who requested it. Queue workers and console commands
 * have no authenticated user to fall back to, which is when this fires: pass the
 * requester explicitly, or have the model return one from
 * `approvalRequester()`.
 */
final class MissingRequesterException extends ApprovalException
{
    public static function forApprovable(Model $approvable): self
    {
        return new self(sprintf(
            'No requester could be determined for [%s]. Pass one to submitForApproval(), return one from approvalRequester(), or run this where a user is authenticated.',
            $approvable::class,
        ));
    }
}
