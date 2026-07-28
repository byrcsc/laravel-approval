<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Exceptions;

/**
 * A rejection ends the request and sends the submitter back to the start, so
 * "no" without a reason is not a decision anybody can act on.
 */
final class CommentRequiredException extends ApprovalException
{
    public static function toReject(): self
    {
        return new self('Rejecting an approval request requires a comment explaining why.');
    }

    public static function toReturn(): self
    {
        return new self('Returning an approval request requires a comment explaining what the submitter must change.');
    }
}
