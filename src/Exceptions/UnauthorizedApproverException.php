<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Exceptions;

use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use Illuminate\Database\Eloquent\Model;

/**
 * The actor has no standing to do this.
 *
 * The engine checks this itself rather than trusting the caller, because the
 * approver list is its own data and nothing else is in a position to check it.
 * A host app's policies and gates sit on top - this is the floor, not the
 * whole of the authorization story.
 */
final class UnauthorizedApproverException extends ApprovalException
{
    public static function notAnApprover(Model $actor, ApprovalRequest $request): self
    {
        return new self(sprintf(
            '[%s] has no assignment on the active stage of approval request %s.',
            self::describe($actor),
            self::ulid($request),
        ));
    }

    /**
     * The assignment exists, but it now belongs to a delegate.
     */
    public static function assignmentWasDelegated(Model $actor, ApprovalRequest $request): self
    {
        return new self(sprintf(
            '[%s] delegated their approval on request %s, so the decision is no longer theirs to make.',
            self::describe($actor),
            self::ulid($request),
        ));
    }

    public static function notTheSubmitter(Model $actor, ApprovalRequest $request): self
    {
        return new self(sprintf(
            '[%s] did not submit approval request %s. Pass force: true to cancel it on their behalf.',
            self::describe($actor),
            self::ulid($request),
        ));
    }

    private static function describe(Model $actor): string
    {
        $key = $actor->getKey();

        return $actor::class.'#'.(is_scalar($key) ? (string) $key : 'unsaved');
    }

    private static function ulid(ApprovalRequest $request): string
    {
        $ulid = $request->getAttributeValue('ulid');

        return is_scalar($ulid) ? (string) $ulid : 'unsaved';
    }
}
