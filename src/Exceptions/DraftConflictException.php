<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Exceptions;

use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;

/**
 * Raised when somebody tries to resolve a conflict that is not there.
 *
 * Note what does *not* throw: reaching the end of the levels and finding the
 * record has moved. That is an ordinary outcome of an approval process running
 * over days, so the request turns `conflicted` and waits for a person - it does
 * not blow up in the last approver's face for something they did not do.
 */
final class DraftConflictException extends ApprovalException
{
    /**
     * @param  list<string>  $attributes
     */
    public function __construct(string $message, public readonly array $attributes = [])
    {
        parent::__construct($message);
    }

    public static function notConflicted(ApprovalRequest $request, RequestState $state): self
    {
        return new self(sprintf(
            'Approval request %s is %s, not conflicted, so there is no conflict to resolve.',
            self::ulid($request),
            $state->value,
        ));
    }

    public static function nothingToApply(ApprovalRequest $request): self
    {
        return new self(sprintf(
            'Approval request %s carries no draft, so there is nothing to reapply.',
            self::ulid($request),
        ));
    }

    public static function approvableIsGone(ApprovalRequest $request): self
    {
        return new self(sprintf(
            'The record approval request %s was raised against no longer exists.',
            self::ulid($request),
        ));
    }

    private static function ulid(ApprovalRequest $request): string
    {
        $ulid = $request->getAttributeValue('ulid');

        return is_scalar($ulid) ? (string) $ulid : 'unsaved';
    }
}
