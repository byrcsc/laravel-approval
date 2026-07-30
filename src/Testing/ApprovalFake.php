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
use ByRcsc\LaravelApproval\Enums\InteractionType;
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
 * the shape the real operation returns. {@see InteractionType} lists what is
 * recorded and names the assertion for each.
 *
 * Queries are not faked. Reads such as `awaiting()`, `pendingFor()`, and
 * `verifyTrail()` run against the database as usual.
 */
final class ApprovalFake extends ApprovalManager
{
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
        ?Model $requester = null,
        ?string $comment = null,
        array $metadata = [],
    ): ApprovalRequest {
        // Resolved the way the real manager resolves it, so an omitted requester
        // records the actor a real submission would attribute the request to -
        // and fails the same way when there is nobody to attribute it to.
        $requester ??= $this->requesterFor($approvable);

        $this->interactions[] = new ApprovalInteraction(
            InteractionType::Submitted,
            $approvable,
            $requester,
            workflow: $workflow,
            comment: $comment,
            metadata: ['new_attributes' => $newAttributes, ...$metadata],
        );

        $request = new ApprovalRequest([
            'approvable_type' => $approvable->getMorphClass(),
            'approvable_id' => $approvable->getKey(),
            'new_attributes' => $newAttributes,
            'requester_type' => $requester->getMorphClass(),
            'requester_id' => $requester->getKey(),
        ]);
        $request->setRelation('approvable', $approvable);
        $request->setRelation('requester', $requester);

        return $request;
    }

    public function decide(ApprovalRequest $request, ApprovalDecision $decision): ApprovalRequest
    {
        $this->interactions[] = new ApprovalInteraction(
            InteractionType::Decision,
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

        $this->interactions[] = new ApprovalInteraction(
            InteractionType::DecisionsMany,
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
        $this->interactions[] = new ApprovalInteraction(
            InteractionType::Withdrawn,
            $request,
            $actor,
            comment: $comment,
        );

        return $request;
    }

    public function delegate(
        ApprovalRequest $request,
        Model $approver,
        Model $delegate,
        ?string $comment = null,
    ): ApprovalRequestAssignment {
        $this->interactions[] = new ApprovalInteraction(
            InteractionType::Delegated,
            $request,
            $approver,
            comment: $comment,
            recipient: $delegate,
        );

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
        $this->interactions[] = new ApprovalInteraction(
            InteractionType::Resubmitted,
            $request,
            $requester,
            workflow: $workflow,
            metadata: ['draft' => $draft],
        );

        return $request;
    }

    public function attach(AttachmentEvidence $evidence): ApprovalAttachment
    {
        $this->interactions[] = new ApprovalInteraction(
            InteractionType::Attached,
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
        $this->interactions[] = new ApprovalInteraction(
            InteractionType::Cancelled,
            $request,
            $actor,
            comment: $comment,
        );

        return $request;
    }

    public function reassign(
        ApprovalRequest $request,
        ApprovalRequestAssignment $assignment,
        Model $to,
        Model $actor,
        ?string $comment = null,
    ): ApprovalRequestAssignment {
        $this->interactions[] = new ApprovalInteraction(
            InteractionType::Reassigned,
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
        $this->interactions[] = new ApprovalInteraction(
            InteractionType::Resynced,
            $request,
            $actor,
            metadata: ['prune' => $prune],
        );

        return [];
    }

    public function resolve(
        ApprovalRequest $request,
        ConflictResolution $resolution,
        ?Model $actor = null,
        ?string $comment = null,
    ): ApprovalRequest {
        $this->interactions[] = new ApprovalInteraction(
            InteractionType::ConflictResolved,
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
        $this->interactions[] = new ApprovalInteraction(
            InteractionType::GuardBypassed,
            $approvable,
            $actor,
            comment: $comment,
        );

        return $callback($approvable);
    }

    /**
     * @param  iterable<int|string, mixed>  $definitions
     */
    public function syncDefinitions(iterable $definitions, bool $dryRun = false): WorkflowSyncResult
    {
        $this->interactions[] = new ApprovalInteraction(
            InteractionType::DefinitionsSynced,
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
        $this->interactions[] = new ApprovalInteraction(InteractionType::OverdueProcessed);

        return [
            'escalated' => [],
            'reminded' => [],
            'stranded' => [],
            'failed' => [],
        ];
    }

    public function assertSubmitted(Model $approvable, ?callable $callback = null): void
    {
        $this->assertRecorded(InteractionType::Submitted, $approvable, $callback);
    }

    public function assertNotSubmitted(Model $approvable): void
    {
        $this->assertNotRecorded(InteractionType::Submitted, $approvable);
    }

    public function assertSubmittedTimes(int $count, ?Model $approvable = null): void
    {
        $this->assertRecordedTimes(InteractionType::Submitted, $count, $approvable);
    }

    public function assertApproved(ApprovalRequest $request, ?callable $callback = null): void
    {
        $this->assertRecorded(InteractionType::Decision, $request, $callback, Verdict::Approve);
    }

    public function assertRejected(ApprovalRequest $request, ?callable $callback = null): void
    {
        $this->assertRecorded(InteractionType::Decision, $request, $callback, Verdict::Reject);
    }

    public function assertReturnedForRevision(ApprovalRequest $request, ?callable $callback = null): void
    {
        $this->assertRecorded(InteractionType::Decision, $request, $callback, Verdict::Return);
    }

    public function assertNotApproved(ApprovalRequest $request): void
    {
        $this->assertNotRecorded(InteractionType::Decision, $request, Verdict::Approve);
    }

    public function assertApprovedTimes(int $count, ?ApprovalRequest $request = null): void
    {
        $this->assertRecordedTimes(InteractionType::Decision, $count, $request, Verdict::Approve);
    }

    public function assertRejectedTimes(int $count, ?ApprovalRequest $request = null): void
    {
        $this->assertRecordedTimes(InteractionType::Decision, $count, $request, Verdict::Reject);
    }

    public function assertApprovedMany(?callable $callback = null): void
    {
        $this->assertRecorded(InteractionType::DecisionsMany, null, $callback, Verdict::Approve);
    }

    public function assertRejectedMany(?callable $callback = null): void
    {
        $this->assertRecorded(InteractionType::DecisionsMany, null, $callback, Verdict::Reject);
    }

    public function assertWithdrawn(ApprovalRequest $request, ?callable $callback = null): void
    {
        $this->assertRecorded(InteractionType::Withdrawn, $request, $callback);
    }

    public function assertCancelled(ApprovalRequest $request, ?callable $callback = null): void
    {
        $this->assertRecorded(InteractionType::Cancelled, $request, $callback);
    }

    public function assertDelegated(ApprovalRequest $request, ?callable $callback = null): void
    {
        $this->assertRecorded(InteractionType::Delegated, $request, $callback);
    }

    public function assertReassigned(ApprovalRequest $request, ?callable $callback = null): void
    {
        $this->assertRecorded(InteractionType::Reassigned, $request, $callback);
    }

    public function assertResynced(ApprovalRequest $request, ?callable $callback = null): void
    {
        $this->assertRecorded(InteractionType::Resynced, $request, $callback);
    }

    public function assertConflictResolved(ApprovalRequest $request, ?callable $callback = null): void
    {
        $this->assertRecorded(InteractionType::ConflictResolved, $request, $callback);
    }

    public function assertResubmitted(ApprovalRequest $request, ?callable $callback = null): void
    {
        $this->assertRecorded(InteractionType::Resubmitted, $request, $callback);
    }

    public function assertAttached(ApprovalRequest $request, ?callable $callback = null): void
    {
        $this->assertRecorded(InteractionType::Attached, $request, $callback);
    }

    public function assertGuardBypassed(Model $approvable, ?callable $callback = null): void
    {
        $this->assertRecorded(InteractionType::GuardBypassed, $approvable, $callback);
    }

    public function assertOverdueProcessed(?callable $callback = null): void
    {
        $this->assertRecorded(InteractionType::OverdueProcessed, null, $callback);
    }

    public function assertDefinitionsSynced(?callable $callback = null): void
    {
        $this->assertRecorded(InteractionType::DefinitionsSynced, null, $callback);
    }

    /**
     * Asserts that no interaction of any type was recorded. The failure message
     * names the types that were, which is why this compares the recorded list
     * rather than counting it.
     */
    public function assertNothingRecorded(): void
    {
        Assert::assertSame(
            [],
            array_map(
                fn (ApprovalInteraction $interaction): string => $interaction->type->value,
                $this->interactions,
            ),
            'Unexpected approval interactions were recorded.',
        );
    }

    private function assertRecorded(
        InteractionType $type,
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

        Assert::assertNotEmpty($matching, "Expected approval interaction [{$type->value}] was not recorded.");
    }

    private function assertNotRecorded(InteractionType $type, ?Model $target, ?Verdict $verdict = null): void
    {
        Assert::assertCount(
            0,
            $this->matching($type, $target, $verdict),
            "Unexpected approval interaction [{$type->value}] was recorded.",
        );
    }

    private function assertRecordedTimes(
        InteractionType $type,
        int $count,
        ?Model $target,
        ?Verdict $verdict = null,
    ): void {
        Assert::assertCount(
            $count,
            $this->matching($type, $target, $verdict),
            "Expected {$count} approval interactions of type [{$type->value}].",
        );
    }

    /**
     * @return list<ApprovalInteraction>
     */
    private function matching(InteractionType $type, ?Model $target, ?Verdict $verdict = null): array
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
