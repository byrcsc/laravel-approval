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
        ?Model $submitter = null,
        ?string $comment = null,
        array $metadata = [],
    ): ApprovalRequest {
        $this->interactions[] = new ApprovalInteraction(
            'submitted',
            $approvable,
            $submitter,
            $workflow,
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
        $this->interactions[] = new ApprovalInteraction(
            'decision',
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
            'bulk_decision',
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
        return $request;
    }

    public function cancel(ApprovalRequest $request, Model $actor, ?string $comment = null): ApprovalRequest
    {
        return $request;
    }

    public function delegate(
        ApprovalRequest $request,
        Model $approver,
        Model $delegate,
        ?string $comment = null,
    ): ApprovalRequestAssignment {
        return new ApprovalRequestAssignment([
            'approver_type' => $approver->getMorphClass(),
            'approver_id' => $approver->getKey(),
            'delegated_to_type' => $delegate->getMorphClass(),
            'delegated_to_id' => $delegate->getKey(),
        ]);
    }

    public function reassign(
        ApprovalRequest $request,
        ApprovalRequestAssignment $assignment,
        Model $to,
        Model $actor,
        ?string $comment = null,
    ): ApprovalRequestAssignment {
        return $assignment;
    }

    public function resync(ApprovalRequest $request, Model $actor, bool $prune = false): array
    {
        return [];
    }

    public function resolve(
        ApprovalRequest $request,
        ConflictResolution $resolution,
        ?Model $actor = null,
        ?string $comment = null,
    ): ApprovalRequest {
        return $request;
    }

    public function reviseAndResubmit(
        ApprovalRequest $request,
        RevisedDraft $draft,
        ?Model $requester = null,
        ApprovalWorkflow|string|null $workflow = null,
    ): ApprovalRequest {
        return $request;
    }

    public function attach(AttachmentEvidence $evidence): ApprovalAttachment
    {
        return new ApprovalAttachment([
            'request_id' => $evidence->request->getKey(),
            'disk' => $evidence->disk,
            'path' => $evidence->path,
            'checksum' => $evidence->checksum,
        ]);
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
        return $callback($approvable);
    }

    public function escalateOverdue(): array
    {
        return [
            'escalated' => [],
            'reminded' => [],
            'stranded' => [],
            'failed' => [],
        ];
    }

    /**
     * @param  iterable<int|string, mixed>  $definitions
     */
    public function syncDefinitions(iterable $definitions, bool $dryRun = false): WorkflowSyncResult
    {
        return new WorkflowSyncResult([], [], [], [], $dryRun);
    }

    public function assertSubmitted(Model $approvable, ?callable $callback = null): void
    {
        $this->assertInteraction('submitted', $approvable, null, $callback);
    }

    public function assertNotSubmitted(Model $approvable): void
    {
        Assert::assertCount(0, $this->matching('submitted', $approvable), 'Unexpected approval submission was recorded.');
    }

    public function assertSubmittedTimes(int $count, ?Model $approvable = null): void
    {
        Assert::assertCount($count, $this->matching('submitted', $approvable), "Expected {$count} approval submissions.");
    }

    public function assertApproved(ApprovalRequest $request, ?callable $callback = null): void
    {
        $this->assertInteraction('decision', $request, Verdict::Approve, $callback);
    }

    public function assertRejected(ApprovalRequest $request, ?callable $callback = null): void
    {
        $this->assertInteraction('decision', $request, Verdict::Reject, $callback);
    }

    public function assertReturned(ApprovalRequest $request, ?callable $callback = null): void
    {
        $this->assertInteraction('decision', $request, Verdict::Return, $callback);
    }

    public function assertNotApproved(ApprovalRequest $request): void
    {
        Assert::assertCount(
            0,
            $this->decisions(Verdict::Approve, $request),
            'Unexpected approval decision was recorded.',
        );
    }

    public function assertApprovedTimes(int $count, ?ApprovalRequest $request = null): void
    {
        Assert::assertCount(
            $count,
            $this->decisions(Verdict::Approve, $request),
            "Expected {$count} approval decisions.",
        );
    }

    public function assertRejectedTimes(int $count, ?ApprovalRequest $request = null): void
    {
        Assert::assertCount(
            $count,
            $this->decisions(Verdict::Reject, $request),
            "Expected {$count} rejection decisions.",
        );
    }

    public function assertBulkApproved(?callable $callback = null): void
    {
        $this->assertBulk(Verdict::Approve, $callback);
    }

    public function assertBulkRejected(?callable $callback = null): void
    {
        $this->assertBulk(Verdict::Reject, $callback);
    }

    public function assertNothingRecorded(): void
    {
        Assert::assertCount(0, $this->interactions, 'Unexpected approval interactions were recorded.');
    }

    private function assertInteraction(
        string $type,
        Model $target,
        ?Verdict $verdict,
        ?callable $callback,
    ): void {
        $matching = array_filter(
            $this->matching($type, $target),
            fn (ApprovalInteraction $interaction): bool => $verdict === null || $interaction->verdict === $verdict,
        );

        if ($callback !== null) {
            $matching = array_filter(
                $matching,
                fn (ApprovalInteraction $interaction): bool => $callback($interaction) === true,
            );
        }

        Assert::assertNotEmpty($matching, "Expected approval interaction [{$type}] was not recorded.");
    }

    /**
     * @return list<ApprovalInteraction>
     */
    private function matching(string $type, ?Model $target): array
    {
        return array_values(array_filter(
            $this->interactions,
            function (ApprovalInteraction $interaction) use ($type, $target): bool {
                if ($interaction->type !== $type) {
                    return false;
                }

                if ($target === null) {
                    return true;
                }

                return $interaction->target instanceof Model && $interaction->target->is($target);
            },
        ));
    }

    /**
     * @return list<ApprovalInteraction>
     */
    private function decisions(Verdict $verdict, ?ApprovalRequest $request): array
    {
        return array_values(array_filter(
            $this->matching('decision', $request),
            fn (ApprovalInteraction $interaction): bool => $interaction->verdict === $verdict,
        ));
    }

    private function assertBulk(Verdict $verdict, ?callable $callback): void
    {
        $matching = array_filter(
            $this->interactions,
            fn (ApprovalInteraction $interaction): bool => $interaction->type === 'bulk_decision'
                && $interaction->verdict === $verdict
                && ($callback === null || $callback($interaction) === true),
        );

        Assert::assertNotEmpty($matching, "Expected bulk {$verdict->value} interaction was not recorded.");
    }
}
