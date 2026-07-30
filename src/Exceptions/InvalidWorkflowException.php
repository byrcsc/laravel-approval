<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Exceptions;

use ByRcsc\LaravelApproval\Contracts\ApproverResolver;
use ByRcsc\LaravelApproval\Contracts\StageCondition;

/**
 * A workflow that cannot produce a decision. Every one of these is raised at
 * submit time, before a single row is written: a request that stalls at stage
 * three because nobody was assigned to it is far more expensive to discover
 * than one that never started.
 */
final class InvalidWorkflowException extends ApprovalException
{
    public static function inactive(string $slug): self
    {
        return new self("The approval workflow [{$slug}] is not active.");
    }

    public static function hasNoStages(string $slug): self
    {
        return new self("The approval workflow [{$slug}] has no stages, so there is nothing to approve.");
    }

    public static function stageHasNoApprovers(string $slug, int $sequence): self
    {
        return new self(
            "Stage {$sequence} of the approval workflow [{$slug}] resolved to no approvers, so the request could never advance past it."
        );
    }

    public static function approvalRuleUnreachable(string $slug, int $sequence, int $required, int $available): self
    {
        return new self(
            "Stage {$sequence} of the approval workflow [{$slug}] has an invalid approval rule: the approval rule requires {$required} approvals but only {$available} assignments were resolved."
        );
    }

    public static function approvalRuleNotPositive(string $slug, int $sequence, int $required): self
    {
        return new self(
            "Stage {$sequence} of the approval workflow [{$slug}] has an invalid approval rule: minimum approvals must be positive, or omitted to require every assignment."
        );
    }

    public static function unknownRejectionPolicy(string $slug, int $sequence, string $policy): self
    {
        return new self(
            "Stage {$sequence} of the approval workflow [{$slug}] has an invalid rejection policy [{$policy}]."
        );
    }

    public static function livingHoldersBelowRequired(string $slug, int $sequence, int $required, int $living): self
    {
        return new self(
            "Stage {$sequence} of the approval workflow [{$slug}] requires {$required} approvals but has only {$living} available assignments."
        );
    }

    public static function ambiguousApprover(string $slug, int $sequence): self
    {
        return new self(
            "Stage {$sequence} of the approval workflow [{$slug}] has an approver entry that sets both a concrete approver and a resolver. Set exactly one."
        );
    }

    public static function emptyApprover(string $slug, int $sequence): self
    {
        return new self(
            "Stage {$sequence} of the approval workflow [{$slug}] has an approver entry that sets neither a concrete approver nor a resolver. Set exactly one."
        );
    }

    public static function concreteApproverMissing(
        string $slug,
        int $sequence,
        string $type,
        int|string $id,
    ): self {
        return new self(
            "Stage {$sequence} of the approval workflow [{$slug}] points at an approver that does not exist [{$type}:{$id}]."
        );
    }

    public static function unknownResolver(string $slug, int $sequence, string $resolver): self
    {
        return new self(
            "Stage {$sequence} of the approval workflow [{$slug}] names the resolver [{$resolver}], which does not exist."
        );
    }

    public static function notAResolver(string $slug, int $sequence, string $resolver): self
    {
        return new self(sprintf(
            'Stage %d of the approval workflow [%s] names the resolver [%s], which does not implement %s.',
            $sequence,
            $slug,
            $resolver,
            ApproverResolver::class,
        ));
    }

    public static function escalationNeedsResolver(int $sequence): self
    {
        return new self(
            "Stage {$sequence} escalates by bringing somebody in, but its escalation_config names no resolver to find them."
        );
    }

    public static function resolverReturnedNonModel(string $resolver): self
    {
        return new self("The approver resolver [{$resolver}] returned something that is not an Eloquent model.");
    }

    public static function resolverReturnedUnsavedModel(string $resolver): self
    {
        return new self("The approver resolver [{$resolver}] returned a model that has not been saved, so it cannot hold an approval assignment.");
    }

    public static function unknownCondition(string $slug, int $sequence, string $condition): self
    {
        return new self(
            "Stage {$sequence} of the approval workflow [{$slug}] names the condition [{$condition}], which does not exist."
        );
    }

    public static function notACondition(string $slug, int $sequence, string $condition): self
    {
        return new self(sprintf(
            'Stage %d of the approval workflow [%s] names the condition [%s], which does not implement %s.',
            $sequence,
            $slug,
            $condition,
            StageCondition::class,
        ));
    }
}
