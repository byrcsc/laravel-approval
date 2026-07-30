<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Exceptions;

use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use Illuminate\Database\Eloquent\Model;

/**
 * The decision has already been made - by this actor, or by the request as a
 * whole. Concurrent decisions are serialized so each assignment is counted
 * at most once.
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

    public static function noActiveStage(ApprovalRequest $request): self
    {
        return new self(sprintf(
            'Approval request %s has no active stage, so there is nothing to decide.',
            self::ulid($request),
        ));
    }

    public static function decisionAlreadyRecorded(Model $actor, ApprovalRequest $request): self
    {
        return new self(sprintf(
            '[%s] has already approved the active stage of approval request %s.',
            $actor::class,
            self::ulid($request),
        ));
    }

    public static function assignmentHasDecision(ApprovalRequest $request): self
    {
        return new self(sprintf(
            'That assignment on approval request %s already has a decision and cannot be moved.',
            self::ulid($request),
        ));
    }

    public static function assignmentIsNotOnRequest(ApprovalRequest $request): self
    {
        return new self(sprintf(
            'That approval assignment does not belong to approval request %s.',
            self::ulid($request),
        ));
    }

    public static function stageIsClosed(ApprovalRequest $request): self
    {
        return new self(sprintf(
            'That approval assignment cannot be moved because its stage is complete on approval request %s.',
            self::ulid($request),
        ));
    }

    public static function notRevisable(ApprovalRequest $request, RequestState $state): self
    {
        return new self(sprintf(
            'Approval request %s is %s and cannot be revised and resubmitted. Only returned, rejected, withdrawn, cancelled, or conflicted requests can.',
            self::ulid($request),
            $state->value,
        ));
    }

    public static function hasNoSubject(ApprovalRequest $request): self
    {
        return new self(sprintf(
            'Approval request %s has lost its subject: the record or the requester it points at no longer exists.',
            self::ulid($request),
        ));
    }

    private static function ulid(ApprovalRequest $request): string
    {
        $ulid = $request->getAttributeValue('ulid');

        return is_scalar($ulid) ? (string) $ulid : 'unsaved';
    }
}
