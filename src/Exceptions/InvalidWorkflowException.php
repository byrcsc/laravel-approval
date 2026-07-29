<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Exceptions;

use ByRcsc\LaravelApproval\Contracts\ApproverResolver;
use ByRcsc\LaravelApproval\Contracts\LevelCondition;

/**
 * A workflow that cannot produce a decision. Every one of these is raised at
 * submit time, before a single row is written: a request that stalls at level
 * three because nobody was assigned to it is far more expensive to discover
 * than one that never started.
 */
final class InvalidWorkflowException extends ApprovalException
{
    public static function inactive(string $slug): self
    {
        return new self("The approval workflow [{$slug}] is not active.");
    }

    public static function hasNoLevels(string $slug): self
    {
        return new self("The approval workflow [{$slug}] has no levels, so there is nothing to approve.");
    }

    public static function levelHasNoApprovers(string $slug, int $sequence): self
    {
        return new self(
            "Level {$sequence} of the approval workflow [{$slug}] resolved to no approvers, so the request could never advance past it."
        );
    }

    public static function quorumUnreachable(string $slug, int $sequence, int $required, int $available): self
    {
        return new self(
            "Level {$sequence} of the approval workflow [{$slug}] requires {$required} approvals but has only {$available} approvers."
        );
    }

    public static function quorumNotPositive(string $slug, int $sequence, int $required): self
    {
        return new self(
            "Level {$sequence} of the approval workflow [{$slug}] requires {$required} approvals. Leave the column null to mean all of them."
        );
    }

    public static function livingQuorumUnreachable(string $slug, int $sequence, int $required, int $living): self
    {
        return new self(
            "Stage {$sequence} of the approval workflow [{$slug}] requires {$required} approvals but has only {$living} available assignments."
        );
    }

    public static function ambiguousApprover(string $slug, int $sequence): self
    {
        return new self(
            "Level {$sequence} of the approval workflow [{$slug}] has an approver entry that sets both a concrete approver and a resolver. Set exactly one."
        );
    }

    public static function emptyApprover(string $slug, int $sequence): self
    {
        return new self(
            "Level {$sequence} of the approval workflow [{$slug}] has an approver entry that sets neither a concrete approver nor a resolver. Set exactly one."
        );
    }

    public static function concreteApproverMissing(
        string $slug,
        int $sequence,
        string $type,
        int|string $id,
    ): self {
        return new self(
            "Level {$sequence} of the approval workflow [{$slug}] points at an approver that does not exist [{$type}:{$id}]."
        );
    }

    public static function unknownResolver(string $slug, int $sequence, string $resolver): self
    {
        return new self(
            "Level {$sequence} of the approval workflow [{$slug}] names the resolver [{$resolver}], which does not exist."
        );
    }

    public static function notAResolver(string $slug, int $sequence, string $resolver): self
    {
        return new self(sprintf(
            'Level %d of the approval workflow [%s] names the resolver [%s], which does not implement %s.',
            $sequence,
            $slug,
            $resolver,
            ApproverResolver::class,
        ));
    }

    public static function escalationNeedsResolver(int $sequence): self
    {
        return new self(
            "Level {$sequence} escalates by bringing somebody in, but its escalation_config names no resolver to find them."
        );
    }

    public static function resolverReturnedNonModel(string $resolver): self
    {
        return new self("The approver resolver [{$resolver}] returned something that is not an Eloquent model.");
    }

    public static function resolverReturnedUnsavedModel(string $resolver): self
    {
        return new self("The approver resolver [{$resolver}] returned a model that has not been saved, so it cannot hold an approver slot.");
    }

    public static function unknownCondition(string $slug, int $sequence, string $condition): self
    {
        return new self(
            "Level {$sequence} of the approval workflow [{$slug}] names the condition [{$condition}], which does not exist."
        );
    }

    public static function notACondition(string $slug, int $sequence, string $condition): self
    {
        return new self(sprintf(
            'Level %d of the approval workflow [%s] names the condition [%s], which does not implement %s.',
            $sequence,
            $slug,
            $condition,
            LevelCondition::class,
        ));
    }
}
