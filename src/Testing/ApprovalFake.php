<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Testing;

use ByRcsc\LaravelApproval\ApprovalManager;
use ByRcsc\LaravelApproval\Data\ApprovalDecision;
use ByRcsc\LaravelApproval\Data\ApprovalInteraction;
use ByRcsc\LaravelApproval\Data\AttachmentEvidence;
use ByRcsc\LaravelApproval\Data\BulkDecisionOutcome;
use ByRcsc\LaravelApproval\Data\BulkDecisionResult;
use ByRcsc\LaravelApproval\Data\RevisedDraft;
use ByRcsc\LaravelApproval\Data\WorkflowSyncResult;
use ByRcsc\LaravelApproval\Enums\ConflictResolution;
use ByRcsc\LaravelApproval\Enums\Verdict;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Models\ApprovalAttachment;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestAssignment;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflow;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Assert;

/**
 * Records approval operations instead of performing them, so application tests
 * can assert approval behavior without writing approval state.
 *
 * Every public mutation on {@see ApprovalManager} is overridden here: it records
 * an {@see ApprovalInteraction} and returns an unsaved model or empty result of
 * the shape the real operation returns. Each recorded operation has a matching
 * assertion below, and {@see self::assertNothingRecorded()} covers all of them.
 *
 * Queries are not faked. Reads such as `awaiting()`, `pendingFor()`, and
 * `verifyTrail()` run against the database as usual.
 */
final class ApprovalFake extends ApprovalManager
{
    public const SUBMITTED = 'submitted';

    public const DECISION = 'decision';

    public const DECISIONS_MANY = 'decisions_many';

    public const WITHDRAWN = 'withdrawn';

    public const CANCELLED = 'cancelled';

    public const DELEGATED = 'delegated';

    public const REASSIGNED = 'reassigned';

    public const RESYNCED = 'resynced';

    public const CONFLICT_RESOLVED = 'conflict_resolved';

    public const RESUBMITTED = 'resubmitted';

    public const ATTACHED = 'attached';

    public const GUARD_BYPASSED = 'guard_bypassed';

    public const OVERDUE_PROCESSED = 'overdue_processed';

    public const DEFINITIONS_SYNCED = 'definitions_synced';

    /** @var list<ApprovalInteraction> */
    private array $interactions = [];

    public function __construct(
        Container $container,
        private readonly ApprovalManager $real,
    ) {
        parent::__construct($container);
    }

    public function fake(): self
    {
        $this->interactions = [];

        return $this;
    }

    public function restore(): ApprovalManager
    {
        $this->container->instance(ApprovalManager::class, $this->real);
        Approval::swap($this->real);

        return $this->real;
    }

    /**
     * @return list<ApprovalInteraction>
     */
    public function interactions(): array
    {
        return $this->interactions;
    }

    /**
     * @param  array<string, mixed>|null  $newAttributes
     * @param  array<string, mixed>  $metadata
     */
    public function submit(
        Model $approvable,
        ApprovalWorkflow|string|null $workflow = null,
        ?array $newAttributes = null,
        ?Model $submitter = null,
        ?string $comment = null,
        array $metadata = [],
    ): ApprovalRequest {
        $this->record(
            self::SUBMITTED,
            $approvable,
            $submitter,
            workflow: $workflow,
            comment: $comment,
            metadata: ['new_attributes' => $newAttributes, ...$metadata],
        );

        $request = new ApprovalRequest([
            'approvable_type' => $approvable->getMorphClass(),
            'approvable_id' => $approvable->getKey(),
            'new_attributes' => $newAttributes,
            'submitter_type' => $submitter?->getMorphClass(),
            'submitter_id' => $submitter?->getKey(),
        ]);
        $request->setRelation('approvable', $approvable);

        if ($submitter !== null) {
            $request->setRelation('submitter', $submitter);
        }

        return $request;
    }

    public function decide(ApprovalRequest $request, ApprovalDecision $decision): ApprovalRequest
    {
        $this->record(
            self::DECISION,
            $request,
            $decision->actor,
            verdict: $decision->verdict,
            comment: $decision->comment(),
            metadata: $decision->metadata,
        );

        return $request;
    }

    /**
     * @param  iterable<int, ApprovalRequest>  $requests
     * @param  callable(ApprovalRequest): ApprovalDecision  $decision
     */
    public function decideMany(iterable $requests, callable $decision): BulkDecisionResult
    {
        $targets = is_array($requests) ? array_values($requests) : array_values(iterator_to_array($requests));
        $outcomes = [];
        $first = null;

        foreach ($targets as $request) {
            $chosen = $decision($request);
            $first ??= $chosen;
            $outcomes[] = BulkDecisionOutcome::succeeded($request, $request);
        }

        $this->record(
            self::DECISIONS_MANY,
            $targets,
            $first?->actor,
            verdict: $first?->verdict,
            comment: $first?->comment(),
            metadata: $first === null ? [] : $first->metadata,
        );

        return new BulkDecisionResult($outcomes);
    }

    public function withdraw(ApprovalRequest $request, Model $actor, ?string $comment = null): ApprovalRequest
    {
        $this->record(self::WITHDRAWN, $request, $actor, comment: $comment);

        return $request;
    }

    public function delegate(
        ApprovalRequest $request,
        Model $approver,
        Model $delegate,
        ?string $comment = null,
    ): ApprovalRequestAssignment {
        $this->record(self::DELEGATED, $request, $approver, comment: $comment, recipient: $delegate);

        return new ApprovalRequestAssignment([
            'approver_type' => $approver->getMorphClass(),
            'approver_id' => $approver->getKey(),
            'delegated_to_type' => $delegate->getMorphClass(),
            'delegated_to_id' => $delegate->getKey(),
        ]);
    }

    public function reviseAndResubmit(
        ApprovalRequest $request,
        RevisedDraft $draft,
        ?Model $requester = null,
        ApprovalWorkflow|string|null $workflow = null,
    ): ApprovalRequest {
        $this->record(
            self::RESUBMITTED,
            $request,
            $requester,
            workflow: $workflow,
            metadata: ['draft' => $draft],
        );

        return $request;
    }

    public function attach(AttachmentEvidence $evidence): ApprovalAttachment
    {
        $this->record(
            self::ATTACHED,
            $evidence->request,
            $evidence->uploader,
            metadata: [
                'disk' => $evidence->disk,
                'path' => $evidence->path,
                'checksum' => $evidence->checksum,
                'name' => $evidence->name,
            ],
        );

        return new ApprovalAttachment([
            'request_id' => $evidence->request->getKey(),
            'disk' => $evidence->disk,
            'path' => $evidence->path,
            'checksum' => $evidence->checksum,
        ]);
    }

    public function cancel(ApprovalRequest $request, Model $actor, ?string $comment = null): ApprovalRequest
    {
        $this->record(self::CANCELLED, $request, $actor, comment: $comment);

        return $request;
    }

    public function reassign(
        ApprovalRequest $request,
        ApprovalRequestAssignment $assignment,
        Model $to,
        Model $actor,
        ?string $comment = null,
    ): ApprovalRequestAssignment {
        $this->record(
            self::REASSIGNED,
            $request,
            $actor,
            comment: $comment,
            metadata: ['assignment' => $assignment],
            recipient: $to,
        );

        return $assignment;
    }

    /**
     * @return array<int, array{added: list<string>, removed: list<string>}>
     */
    public function resync(ApprovalRequest $request, Model $actor, bool $prune = false): array
    {
        $this->record(self::RESYNCED, $request, $actor, metadata: ['prune' => $prune]);

        return [];
    }

    public function resolve(
        ApprovalRequest $request,
        ConflictResolution $resolution,
        ?Model $actor = null,
        ?string $comment = null,
    ): ApprovalRequest {
        $this->record(
            self::CONFLICT_RESOLVED,
            $request,
            $actor,
            comment: $comment,
            metadata: ['resolution' => $resolution],
        );

        return $request;
    }

    /**
     * @template TValue
     *
     * @param  callable(Model): TValue  $callback
     * @return TValue
     */
    public function withoutGuard(
        Model $approvable,
        callable $callback,
        ?Model $actor = null,
        ?string $comment = null,
    ): mixed {
        $this->record(self::GUARD_BYPASSED, $approvable, $actor, comment: $comment);

        return $callback($approvable);
    }

    /**
     * @param  iterable<int|string, mixed>  $definitions
     */
    public function syncDefinitions(iterable $definitions, bool $dryRun = false): WorkflowSyncResult
    {
        $this->record(
            self::DEFINITIONS_SYNCED,
            [],
            metadata: [
                'definitions' => is_array($definitions) ? $definitions : iterator_to_array($definitions),
                'dry_run' => $dryRun,
            ],
        );

        return new WorkflowSyncResult([], [], [], [], $dryRun);
    }

    /**
     * @return array{escalated: list<string>, reminded: list<string>, stranded: list<string>, failed: array<string, string>}
     */
    public function escalateOverdue(): array
    {
        $this->record(self::OVERDUE_PROCESSED, []);

        return [
            'escalated' => [],
            'reminded' => [],
            'stranded' => [],
            'failed' => [],
        ];
    }

    public function assertSubmitted(Model $approvable, ?callable $callback = null): void
    {
        $this->assertRecorded(self::SUBMITTED, $approvable, $callback);
    }

    public function assertNotSubmitted(Model $approvable): void
    {
        $this->assertNotRecorded(self::SUBMITTED, $approvable);
    }

    public function assertSubmittedTimes(int $count, ?Model $approvable = null): void
    {
        $this->assertRecordedTimes(self::SUBMITTED, $count, $approvable);
    }

    public function assertApproved(ApprovalRequest $request, ?callable $callback = null): void
    {
        $this->assertRecorded(self::DECISION, $request, $callback, Verdict::Approve);
    }

    public function assertRejected(ApprovalRequest $request, ?callable $callback = null): void
    {
        $this->assertRecorded(self::DECISION, $request, $callback, Verdict::Reject);
    }

    public function assertReturnedForRevision(ApprovalRequest $request, ?callable $callback = null): void
    {
        $this->assertRecorded(self::DECISION, $request, $callback, Verdict::Return);
    }

    public function assertNotApproved(ApprovalRequest $request): void
    {
        $this->assertNotRecorded(self::DECISION, $request, Verdict::Approve);
    }

    public function assertApprovedTimes(int $count, ?ApprovalRequest $request = null): void
    {
        $this->assertRecordedTimes(self::DECISION, $count, $request, Verdict::Approve);
    }

    public function assertRejectedTimes(int $count, ?ApprovalRequest $request = null): void
    {
        $this->assertRecordedTimes(self::DECISION, $count, $request, Verdict::Reject);
    }

    public function assertApprovedMany(?callable $callback = null): void
    {
        $this->assertRecorded(self::DECISIONS_MANY, null, $callback, Verdict::Approve);
    }

    public function assertRejectedMany(?callable $callback = null): void
    {
        $this->assertRecorded(self::DECISIONS_MANY, null, $callback, Verdict::Reject);
    }

    public function assertWithdrawn(ApprovalRequest $request, ?callable $callback = null): void
    {
        $this->assertRecorded(self::WITHDRAWN, $request, $callback);
    }

    public function assertCancelled(ApprovalRequest $request, ?callable $callback = null): void
    {
        $this->assertRecorded(self::CANCELLED, $request, $callback);
    }

    public function assertDelegated(ApprovalRequest $request, ?callable $callback = null): void
    {
        $this->assertRecorded(self::DELEGATED, $request, $callback);
    }

    public function assertReassigned(ApprovalRequest $request, ?callable $callback = null): void
    {
        $this->assertRecorded(self::REASSIGNED, $request, $callback);
    }

    public function assertResynced(ApprovalRequest $request, ?callable $callback = null): void
    {
        $this->assertRecorded(self::RESYNCED, $request, $callback);
    }

    public function assertConflictResolved(ApprovalRequest $request, ?callable $callback = null): void
    {
        $this->assertRecorded(self::CONFLICT_RESOLVED, $request, $callback);
    }

    public function assertResubmitted(ApprovalRequest $request, ?callable $callback = null): void
    {
        $this->assertRecorded(self::RESUBMITTED, $request, $callback);
    }

    public function assertAttached(ApprovalRequest $request, ?callable $callback = null): void
    {
        $this->assertRecorded(self::ATTACHED, $request, $callback);
    }

    public function assertGuardBypassed(Model $approvable, ?callable $callback = null): void
    {
        $this->assertRecorded(self::GUARD_BYPASSED, $approvable, $callback);
    }

    public function assertOverdueProcessed(?callable $callback = null): void
    {
        $this->assertRecorded(self::OVERDUE_PROCESSED, null, $callback);
    }

    public function assertDefinitionsSynced(?callable $callback = null): void
    {
        $this->assertRecorded(self::DEFINITIONS_SYNCED, null, $callback);
    }

    /**
     * Every interaction category the fake records, so the name of this assertion
     * stays truthful as the surface changes.
     */
    public function assertNothingRecorded(): void
    {
        Assert::assertSame(
            [],
            array_map(
                fn (ApprovalInteraction $interaction): string => $interaction->type,
                $this->interactions,
            ),
            'Unexpected approval interactions were recorded.',
        );
    }

    /**
     * @param  Model|list<ApprovalRequest>  $target
     * @param  array<string, mixed>  $metadata
     */
    private function record(
        string $type,
        Model|array $target,
        ?Model $actor = null,
        ApprovalWorkflow|string|null $workflow = null,
        ?Verdict $verdict = null,
        ?string $comment = null,
        array $metadata = [],
        ?Model $recipient = null,
    ): void {
        $this->interactions[] = new ApprovalInteraction(
            $type,
            $target,
            $actor,
            $workflow,
            $verdict,
            $comment,
            $metadata,
            $recipient,
        );
    }

    private function assertRecorded(
        string $type,
        ?Model $target,
        ?callable $callback,
        ?Verdict $verdict = null,
    ): void {
        $matching = $this->matching($type, $target, $verdict);

        if ($callback !== null) {
            $matching = array_values(array_filter(
                $matching,
                fn (ApprovalInteraction $interaction): bool => $callback($interaction) === true,
            ));
        }

        Assert::assertNotEmpty($matching, "Expected approval interaction [{$type}] was not recorded.");
    }

    private function assertNotRecorded(string $type, ?Model $target, ?Verdict $verdict = null): void
    {
        Assert::assertCount(
            0,
            $this->matching($type, $target, $verdict),
            "Unexpected approval interaction [{$type}] was recorded.",
        );
    }

    private function assertRecordedTimes(
        string $type,
        int $count,
        ?Model $target,
        ?Verdict $verdict = null,
    ): void {
        Assert::assertCount(
            $count,
            $this->matching($type, $target, $verdict),
            "Expected {$count} approval interactions of type [{$type}].",
        );
    }

    /**
     * @return list<ApprovalInteraction>
     */
    private function matching(string $type, ?Model $target, ?Verdict $verdict = null): array
    {
        return array_values(array_filter(
            $this->interactions,
            function (ApprovalInteraction $interaction) use ($type, $target, $verdict): bool {
                if ($interaction->type !== $type) {
                    return false;
                }

                if ($verdict !== null && $interaction->verdict !== $verdict) {
                    return false;
                }

                if ($target === null) {
                    return true;
                }

                return $interaction->target instanceof Model && $interaction->target->is($target);
            },
        ));
    }
}
