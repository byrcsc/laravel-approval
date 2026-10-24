<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Enums;

/**
 * The approval operations the test fake records.
 *
 * One case per retained public mutation, and {@see self::assertion()} names the
 * fake method that asserts it. Keeping the pairing here rather than deriving it
 * from a naming convention means a new case has to state its own assertion.
 */
enum InteractionType: string
{
    case Submitted = 'submitted';
    case Decision = 'decision';
    case DecisionsMany = 'decisions_many';
    case Withdrawn = 'withdrawn';
    case Cancelled = 'cancelled';
    case Delegated = 'delegated';
    case DelegationGranted = 'delegation_granted';
    case DelegationRevoked = 'delegation_revoked';
    case DelegationsApplied = 'delegations_applied';
    case Reassigned = 'reassigned';
    case Resynced = 'resynced';
    case ConflictResolved = 'conflict_resolved';
    case Resubmitted = 'resubmitted';
    case Attached = 'attached';
    case GuardBypassed = 'guard_bypassed';
    case OverdueProcessed = 'overdue_processed';
    case DefinitionsSynced = 'definitions_synced';

    /**
     * The fake assertion that covers this interaction. Decisions are recorded
     * under one case and distinguished by verdict, so they name the approval
     * assertion; the rejection and return assertions sit beside it.
     */
    public function assertion(): string
    {
        return match ($this) {
            self::Submitted => 'assertSubmitted',
            self::Decision => 'assertApproved',
            self::DecisionsMany => 'assertApprovedMany',
            self::Withdrawn => 'assertWithdrawn',
            self::Cancelled => 'assertCancelled',
            self::Delegated => 'assertDelegated',
            self::DelegationGranted => 'assertDelegationGranted',
            self::DelegationRevoked => 'assertDelegationRevoked',
            self::DelegationsApplied => 'assertDelegationsApplied',
            self::Reassigned => 'assertReassigned',
            self::Resynced => 'assertResynced',
            self::ConflictResolved => 'assertConflictResolved',
            self::Resubmitted => 'assertResubmitted',
            self::Attached => 'assertAttached',
            self::GuardBypassed => 'assertGuardBypassed',
            self::OverdueProcessed => 'assertOverdueProcessed',
            self::DefinitionsSynced => 'assertDefinitionsSynced',
        };
    }
}
