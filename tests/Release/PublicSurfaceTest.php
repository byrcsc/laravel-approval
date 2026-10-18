<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\ApprovalManager;
use ByRcsc\LaravelApproval\Concerns\Approvable;
use ByRcsc\LaravelApproval\Data\ApprovalInteraction;
use ByRcsc\LaravelApproval\Data\StageProgress;
use ByRcsc\LaravelApproval\Enums\InteractionType;
use ByRcsc\LaravelApproval\Events\ApprovalEvent;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Policies\ApprovalRequestPolicy;
use ByRcsc\LaravelApproval\Testing\ApprovalFake;

/**
 * The intentionally small pre-1.0 surface, pinned member by member.
 *
 * These assertions are exact rather than subset checks. A new public member is a
 * deliberate addition to the released API, so adding one should require adding
 * it here too. That is what stops duplicate helpers accumulating again.
 */
it('pins the facade and manager command and query surface', function (): void {
    expect(declaredMethods(ApprovalManager::class))->toBe([
        'applyDelegations',
        'approve',
        'approveMany',
        'attach',
        'awaiting',
        'canApprove',
        'canDelegate',
        'canWithdraw',
        'cancel',
        'decide',
        'decideMany',
        'delegate',
        'delegationEligibility',
        'delegationsFrom',
        'eligibility',
        'escalateOverdue',
        'fake',
        'findWorkflow',
        'grantDelegation',
        'overdue',
        'pendingFor',
        'reassign',
        'reject',
        'rejectMany',
        'requesterFor',
        'requestsFor',
        'resolve',
        'resync',
        'returnForRevision',
        'reviseAndResubmit',
        'revokeDelegation',
        'submit',
        'syncDefinitions',
        'verifyTrail',
        'withdraw',
        'withdrawalEligibility',
        'withoutGuard',
        'workflow',
        'workflowFor',
        'workflows',
        'workflowsFor',
    ]);
});

it('documents every manager method on the facade and nothing else', function (): void {
    $docblock = (string) (new ReflectionClass(Approval::class))->getDocComment();

    preg_match_all('/@method static [^\n]*?\b(\w+)\(/', $docblock, $matches);
    $documented = $matches[1];
    sort($documented);

    expect(array_values(array_unique($documented)))->toBe(declaredMethods(ApprovalManager::class));
});

it('pins the approvable trait to model-centric members', function (): void {
    expect(declaredMethods(Approvable::class))->toBe([
        // Configuration seams.
        'approvableAttributes',
        'approvalAutoSubmission',
        // Conveniences for the model's current request.
        'approvalEligibility',
        'approvalMode',
        // Relationships.
        'approvalRequester',
        'approvalRequests',
        'approvalWorkflow',
        'bootApprovable',
        'canBeApprovedBy',
        'closeApprovalOnSoftDelete',
        'currentApprovalStage',
        'currentStageApprovers',
        'isPendingApproval',
        'latestApprovalRequest',
        'latestApprovalTimeline',
        'pendingApprovalApprovers',
        'pendingApprovalRequest',
        'pendingDraft',
        // Model scopes.
        'scopeApprovalApproved',
        'scopeApprovalCancelled',
        'scopeApprovalConflicted',
        'scopeApprovalRejected',
        'scopeApprovalReturned',
        'scopeApprovalState',
        'scopeApprovalSuperseded',
        'scopeApprovalWithdrawn',
        'scopeAwaitingApprovalBy',
        'scopeEverInApprovalState',
        'scopePendingApproval',
        'scopeWithoutPendingApproval',
        'shouldAutoSubmitForApproval',
        // Submission, and the guard-mode escape hatch.
        'submitForApproval',
        'withoutApprovalGuard',
    ]);
});

it('keeps commands off the approvable trait and the request model', function (): void {
    $commands = [
        'approve', 'reject', 'returnForRevision', 'decide', 'withdraw', 'cancel',
        'delegate', 'reassign', 'resync', 'resolve', 'reviseAndResubmit', 'attach',
        'withoutGuard', 'syncDefinitions', 'escalateOverdue', 'verifyTrail',
    ];

    foreach ([Approvable::class, ApprovalRequest::class] as $class) {
        expect(array_intersect(declaredMethods($class), $commands))
            ->toBe([], "[{$class}] must not mirror facade commands.");
    }
});

it('mirrors exactly one facade operation on the trait, and documents why', function (): void {
    // Guard mode is a model-level feature - the interception lives on the
    // model's saving hook - so the bypass is the one operation offered on both
    // the model and the facade. Any second exception should be argued for here.
    $trait = new ReflectionClass(Approvable::class);
    $mirrors = array_values(array_filter(
        declaredMethods(Approvable::class),
        fn (string $method): bool => in_array($method, ['withoutApprovalGuard'], true),
    ));

    expect($mirrors)->toBe(['withoutApprovalGuard'])
        ->and((string) $trait->getMethod('withoutApprovalGuard')->getDocComment())
        ->toContain('Approval::withoutGuard(')
        ->and((string) $trait->getDocComment())
        ->toContain('one deliberate exception');
});

it('pins the request model to state, relationships, progress, and authorization', function (): void {
    expect(declaredMethods(ApprovalRequest::class))->toBe([
        // Relationships.
        'actions',
        'approvable',
        // Authorization questions.
        'approvalEligibility',
        'attachments',
        'canBeApprovedBy',
        'canBeDelegatedBy',
        'canBeWithdrawnBy',
        'constrainAwaitingUser',
        'currentStage',
        'currentStageApprovers',
        'delegationEligibility',
        'factory',
        'getTable',
        'involves',
        'pendingApprovers',
        'requester',
        'resubmissions',
        'resubmittedFrom',
        // Query scopes.
        'scopeAwaitingUser',
        'scopeForApprovable',
        'scopeOverdue',
        'scopePending',
        'scopeUnresolved',
        'scopeWithProgress',
        // Progress and state.
        'stageProgress',
        'stages',
        'timeline',
        'wasRequestedBy',
        'withdrawalEligibility',
        'workflow',
        'workflowSlug',
    ]);
});

it('pins the shipped policy abilities', function (): void {
    expect(declaredMethods(ApprovalRequestPolicy::class))->toBe([
        'approve',
        'cancel',
        'delegate',
        'reject',
        'returnForRevision',
        'view',
        'withdraw',
    ]);
});

it('pins the fake recording and assertion surface', function (): void {
    expect(declaredMethods(ApprovalFake::class))->toBe([
        'applyDelegations',
        'assertApproved',
        'assertApprovedMany',
        'assertApprovedTimes',
        'assertAttached',
        'assertCancelled',
        'assertConflictResolved',
        'assertDefinitionsSynced',
        'assertDelegated',
        'assertDelegationGranted',
        'assertDelegationRevoked',
        'assertDelegationsApplied',
        'assertGuardBypassed',
        'assertNotApproved',
        'assertNotSubmitted',
        'assertNothingRecorded',
        'assertOverdueProcessed',
        'assertReassigned',
        'assertRejected',
        'assertRejectedMany',
        'assertRejectedTimes',
        'assertResubmitted',
        'assertResynced',
        'assertReturnedForRevision',
        'assertSubmitted',
        'assertSubmittedTimes',
        'assertWithdrawn',
        'attach',
        'cancel',
        'decide',
        'decideMany',
        'delegate',
        'escalateOverdue',
        'fake',
        'grantDelegation',
        'interactions',
        'reassign',
        'resolve',
        'restore',
        'resync',
        'reviseAndResubmit',
        'revokeDelegation',
        'submit',
        'syncDefinitions',
        'withdraw',
        'withoutGuard',
    ]);
});

it('pairs every recorded interaction type with an assertion', function (): void {
    $assertions = declaredMethods(ApprovalFake::class);

    foreach (InteractionType::cases() as $type) {
        expect(in_array($type->assertion(), $assertions, true))
            ->toBeTrue("Interaction [{$type->value}] names a missing [{$type->assertion()}] assertion.");
    }
});

it('pins the public value objects and their readable members', function (): void {
    expect(classesIn('Data'))->toBe([
        'ApprovalDecision',
        'ApprovalInteraction',
        'AssignmentSnapshot',
        'AttachmentEvidence',
        'AutoSubmission',
        'BulkDecisionOutcome',
        'BulkDecisionResult',
        'DecisionContext',
        'DelegationEligibility',
        'Draft',
        'Eligibility',
        'RevisedDraft',
        'StageHealth',
        'StageProgress',
        'StageSnapshot',
        'TimelineEntry',
        'WithdrawalEligibility',
        'WorkflowSyncResult',
    ]);

    expect(declaredProperties(StageProgress::class))->toBe([
        'approvalsReceived',
        'approvalsRequired',
        'assignedApprovers',
        'decisionsRecorded',
        'possibleApprovals',
        'rejectionPolicy',
        'rejectionsReceived',
        'remainingApprovals',
        'status',
    ]);

    expect(declaredProperties(ApprovalInteraction::class))->toBe([
        'actor',
        'comment',
        'metadata',
        'recipient',
        'target',
        'type',
        'verdict',
        'workflow',
    ]);
});

it('pins the public enums and their cases', function (): void {
    $cases = [];

    foreach (classesIn('Enums') as $enum) {
        $class = 'ByRcsc\\LaravelApproval\\Enums\\'.$enum;
        $cases[$enum] = array_map(
            fn (ReflectionEnumBackedCase $case): string => (string) $case->getBackingValue(),
            (new ReflectionEnum($class))->getCases(),
        );
    }

    expect($cases)->toBe([
        'ActionType' => [
            'submitted', 'approved', 'rejected', 'delegated', 'delegation_ended', 'reassigned', 'escalated',
            'commented', 'withdrawn', 'cancelled', 'system_closed', 'superseded',
            'resubmitted', 'applied', 'conflicted', 'resynced', 'guard_bypassed',
            'returned', 'stage_skipped', 'reminded', 'attachment_added',
        ],
        'ApprovalMode' => ['explicit', 'guard'],
        'ApproverSource' => ['assigned', 'resolved', 'delegated', 'reassigned', 'escalated', 'resynced'],
        'ConflictResolution' => ['reapply', 'discard'],
        'DelegationIneligibility' => [
            'request_closed', 'no_active_stage', 'not_an_approver', 'assignment_delegated',
            'decision_already_recorded', 'approver_missing', 'cannot_delegate_to_self',
            'delegate_already_assigned', 'delegate_missing',
        ],
        'EscalationAction' => ['record_only', 'escalate', 'auto_approve', 'auto_reject'],
        'Ineligibility' => [
            'request_closed', 'no_active_stage', 'not_an_approver', 'assignment_delegated',
            'decision_already_recorded', 'self_approval',
        ],
        'InteractionType' => [
            'submitted', 'decision', 'decisions_many', 'withdrawn', 'cancelled',
            'delegated', 'delegation_granted', 'delegation_revoked', 'delegations_applied',
            'reassigned', 'resynced', 'conflict_resolved',
            'resubmitted', 'attached', 'guard_bypassed', 'overdue_processed',
            'definitions_synced',
        ],
        'RejectionPolicy' => ['approval_impossible', 'any_rejection_vetoes'],
        'RequestState' => [
            'pending', 'approved', 'rejected', 'returned', 'conflicted', 'withdrawn',
            'cancelled', 'superseded',
        ],
        'StageStatus' => ['queued', 'active', 'approved', 'rejected', 'returned', 'skipped'],
        'TimelineState' => [
            'queued', 'active', 'approved', 'rejected', 'returned', 'skipped',
            'condition_skipped',
        ],
        'Verdict' => ['approve', 'reject', 'return'],
        'WithdrawalIneligibility' => ['request_closed', 'not_requester'],
    ]);
});

it('pins the public events and the facts each one carries', function (): void {
    $events = [];

    foreach (classesIn('Events') as $event) {
        $class = 'ByRcsc\\LaravelApproval\\Events\\'.$event;
        $events[$event] = declaredProperties($class);
    }

    expect($events)->toBe([
        'ApprovalApproved' => ['request'],
        'ApprovalCancelled' => ['actor', 'comment', 'request'],
        'ApprovalCompleted' => ['request'],
        'ApprovalDelegated' => ['approver', 'assignment', 'comment', 'delegate', 'request'],
        'ApprovalEvent' => ['request'],
        'ApprovalRejected' => ['actor', 'comment', 'request', 'stage'],
        'ApprovalResubmitted' => ['previous', 'request'],
        'ApprovalReturned' => ['actor', 'comment', 'request', 'stage'],
        'ApprovalSubmitted' => ['request', 'stage'],
        'ApprovalSystemClosed' => ['reason', 'request'],
        'ApprovalWithdrawn' => ['actor', 'comment', 'request'],
        'ApproverReassigned' => ['actor', 'assignment', 'from', 'request', 'to'],
        'ApproversEscalated' => ['assignments', 'request', 'stage'],
        'ApproversResynced' => ['actor', 'addedAssignments', 'changes', 'request'],
        'DelegationEnded' => ['approver', 'assignment', 'delegate', 'request'],
        'DraftApplied' => ['approvable', 'draft', 'request'],
        'DraftConflicted' => ['approvable', 'attributes', 'request'],
        'StageApprovalRecorded' => ['actor', 'approvalsReceived', 'approvalsRequired', 'request', 'stage'],
        'StageCompleted' => ['next', 'request', 'stage'],
        'StageOverdue' => ['action', 'request', 'stage'],
        'StageOverdueReminder' => ['action', 'request', 'stage'],
        'StageStranded' => ['livingHolders', 'request', 'requiredApprovals', 'stage'],
    ]);

    foreach (classesIn('Events') as $event) {
        $class = 'ByRcsc\\LaravelApproval\\Events\\'.$event;

        expect(is_a($class, ApprovalEvent::class, true))->toBeTrue("[{$event}] must be an ApprovalEvent.");
    }
});
