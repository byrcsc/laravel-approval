<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Exceptions;

use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use Illuminate\Database\Eloquent\Model;

/**
 * The decision has already been made - by this actor, or by the request as a
 * whole. Two approvers clicking at the same moment is the ordinary case; the
 * loser of that race lands here rather than double-counting toward a quorum.
 */
final class AlreadyDecidedException extends ApprovalException
{
    public static function requestIsClosed(ApprovalRequest $request, RequestState $state): self
    {
        return new self(sprintf(
            'Approval request %s was already %s and cannot be decided again.',
            self::ulid($request),
            $state->value,
        ));
    }

    public static function noActiveStep(ApprovalRequest $request): self
    {
        return new self(sprintf(
            'Approval request %s has no active level, so there is nothing to decide.',
            self::ulid($request),
        ));
    }

    public static function actorHasVoted(Model $actor, ApprovalRequest $request): self
    {
        return new self(sprintf(
            '[%s] has already approved the active level of approval request %s.',
            $actor::class,
            self::ulid($request),
        ));
    }

    public static function seatHasVoted(ApprovalRequest $request): self
    {
        return new self(sprintf(
            'That seat on approval request %s has already been used to approve. Moving it would put somebody behind a decision they never made.',
            self::ulid($request),
        ));
    }

    public static function seatIsNotOnRequest(ApprovalRequest $request): self
    {
        return new self(sprintf(
            'That approver slot does not belong to approval request %s.',
            self::ulid($request),
        ));
    }

    public static function stepIsClosed(ApprovalRequest $request): self
    {
        return new self(sprintf(
            'That approver slot cannot be moved because its level has already been decided on approval request %s.',
            self::ulid($request),
        ));
    }

    public static function notRetryable(ApprovalRequest $request, RequestState $state): self
    {
        return new self(sprintf(
            'Approval request %s is %s and cannot be resubmitted. Only rejected, cancelled, or conflicted requests can.',
            self::ulid($request),
            $state->value,
        ));
    }

    public static function retryHasNoSubject(ApprovalRequest $request): self
    {
        return new self(sprintf(
            'Approval request %s cannot be resubmitted: the record or the submitter it points at no longer exists.',
            self::ulid($request),
        ));
    }

    public static function notReturned(ApprovalRequest $request, RequestState $state): self
    {
        return new self(sprintf(
            'Approval request %s is %s and cannot be resumed. Only returned requests can.',
            self::ulid($request),
            $state->value,
        ));
    }

    private static function ulid(ApprovalRequest $request): string
    {
        $ulid = $request->getAttributeValue('ulid');

        return is_scalar($ulid) ? (string) $ulid : 'unsaved';
    }
}
