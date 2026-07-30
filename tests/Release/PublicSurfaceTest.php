<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\ApprovalManager;
use ByRcsc\LaravelApproval\Concerns\Approvable;
use ByRcsc\LaravelApproval\Data\ApprovalInteraction;
use ByRcsc\LaravelApproval\Data\StageProgress;
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

/**
 * @param  class-string  $class
 * @return list<string>
 */
function declaredMethods(string $class): array
{
    $names = [];

    foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() !== $class || str_starts_with($method->getName(), '__')) {
            continue;
        }

        $names[] = $method->getName();
    }

    sort($names);

    return $names;
}

/**
 * @param  class-string  $class
 * @return list<string>
 */
function declaredProperties(string $class): array
{
    $names = [];

    foreach ((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
        $names[] = $property->getName();
    }

    sort($names);

    return $names;
}

/**
 * @return list<string>
 */
function classesIn(string $directory): array
{
    $files = glob(__DIR__.'/../../src/'.$directory.'/*.php');
    $names = array_map(
        fn (string $file): string => basename($file, '.php'),
        $files === false ? [] : $files,
    );
    sort($names);

    return $names;
}

it('pins the facade and manager command and query surface', function (): void {
    expect(declaredMethods(ApprovalManager::class))->toBe([
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
        'eligibility',
        'escalateOverdue',
        'fake',
        'findWorkflow',
        'overdue',
        'pendingFor',
        'reassign',
        'reject',
        'rejectMany',
        'requestsFor',
        'resolve',
        'resync',
        'returnForRevision',
        'reviseAndResubmit',
        'submit',
        'submitterFor',
        'syncDefinitions',
        'verifyTrail',
        'withdraw',
        'withdrawalEligibility',
        'withoutGuard',
        'workflow',
        'workflowFor',
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
        'approvalRequests',
        'approvalSubmitter',
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
    ];

    foreach ([Approvable::class, ApprovalRequest::class] as $class) {
        expect(array_intersect(declaredMethods($class), $commands))
            ->toBe([], "[{$class}] must not mirror facade commands.");
    }
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
        'submitter',
        'timeline',
        'wasSubmittedBy',
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
        'assertApproved',
        'assertApprovedMany',
        'assertApprovedTimes',
        'assertAttached',
        'assertCancelled',
        'assertConflictResolved',
        'assertDefinitionsSynced',
        'assertDelegated',
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
        'interactions',
        'reassign',
        'resolve',
        'restore',
        'resync',
        'reviseAndResubmit',
        'submit',
        'syncDefinitions',
        'withdraw',
        'withoutGuard',
    ]);
});

it('pairs every recorded interaction type with an assertion', function (): void {
    $types = (new ReflectionClass(ApprovalFake::class))->getConstants();
    $assertions = declaredMethods(ApprovalFake::class);

    foreach ($types as $name => $type) {
        $expected = 'assert'.str_replace(' ', '', ucwords(str_replace('_', ' ', (string) $type)));
        $expected = match ($expected) {
            'assertDecision' => 'assertApproved',
            'assertDecisionsMany' => 'assertApprovedMany',
            default => $expected,
        };

        expect(in_array($expected, $assertions, true))
            ->toBeTrue("Interaction [{$name}] has no matching [{$expected}] assertion.");
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
            'submitted', 'approved', 'rejected', 'delegated', 'reassigned', 'escalated',
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
