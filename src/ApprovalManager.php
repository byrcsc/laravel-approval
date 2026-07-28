<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval;

use ByRcsc\LaravelApproval\Actions\ApproveStep;
use ByRcsc\LaravelApproval\Actions\CancelRequest;
use ByRcsc\LaravelApproval\Actions\DelegateApproval;
use ByRcsc\LaravelApproval\Actions\EscalateOverdueSteps;
use ByRcsc\LaravelApproval\Actions\ReassignApprover;
use ByRcsc\LaravelApproval\Actions\RejectRequest;
use ByRcsc\LaravelApproval\Actions\ResolveConflict;
use ByRcsc\LaravelApproval\Actions\ResubmitRequest;
use ByRcsc\LaravelApproval\Actions\ResyncRequestApprovers;
use ByRcsc\LaravelApproval\Actions\SubmitForApproval;
use ByRcsc\LaravelApproval\Data\ApprovalDecision;
use ByRcsc\LaravelApproval\Enums\ConflictResolution;
use ByRcsc\LaravelApproval\Enums\Verdict;
use ByRcsc\LaravelApproval\Exceptions\MissingSubmitterException;
use ByRcsc\LaravelApproval\Exceptions\WorkflowNotFoundException;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStepApprover;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflow;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * The front door behind the `Approval` facade: finding workflows, finding
 * requests, and working out who or what the caller left implicit.
 *
 * It holds no behavior of its own. Anything that changes a request's state
 * lives in an action, and this hands off to one - the moment this class starts
 * deciding things is the moment it starts becoming the god service the design
 * set out to avoid.
 */
final class ApprovalManager
{
    public function __construct(private readonly Container $container) {}

    public function findWorkflow(string $slug): ?ApprovalWorkflow
    {
        /** @var ApprovalWorkflow|null */
        return ApprovalWorkflow::query()->where('slug', $slug)->first();
    }

    public function workflow(string $slug): ApprovalWorkflow
    {
        return $this->findWorkflow($slug) ?? throw WorkflowNotFoundException::forSlug($slug);
    }

    /**
     * Three places a workflow can come from, in order of how specific they are:
     * the call, the model, and the config map. The first that answers wins.
     */
    public function workflowFor(Model $approvable, ApprovalWorkflow|string|null $given = null): ApprovalWorkflow
    {
        $resolved = $this->coerceWorkflow($given)
            ?? $this->coerceWorkflow($this->declaredWorkflow($approvable))
            ?? $this->coerceWorkflow($this->mappedWorkflow($approvable));

        return $resolved ?? throw WorkflowNotFoundException::forApprovable($approvable);
    }

    /**
     * @param  array<string, mixed>|null  $newAttributes  null gates the record itself rather than an edit to it
     */
    public function submit(
        Model $approvable,
        ApprovalWorkflow|string|null $workflow = null,
        ?array $newAttributes = null,
        ?Model $submitter = null,
    ): ApprovalRequest {
        /** @var SubmitForApproval $submit */
        $submit = $this->container->make(SubmitForApproval::class);

        return $submit(
            $approvable,
            $this->workflowFor($approvable, $workflow),
            $newAttributes,
            $submitter ?? $this->submitterFor($approvable),
        );
    }

    /**
     * The generic entry point: one decision, whichever way it went. A
     * controller that renders one form for both buttons wants this.
     */
    public function decide(ApprovalRequest $request, ApprovalDecision $decision): ApprovalRequest
    {
        return match ($decision->verdict) {
            Verdict::Approve => $this->container->make(ApproveStep::class)($request, $decision),
            Verdict::Reject => $this->container->make(RejectRequest::class)($request, $decision),
        };
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function approve(ApprovalRequest $request, Model $actor, ?string $comment = null, array $metadata = []): ApprovalRequest
    {
        return $this->decide($request, ApprovalDecision::approve($actor, $comment, $metadata));
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function reject(ApprovalRequest $request, Model $actor, string $comment, array $metadata = []): ApprovalRequest
    {
        return $this->decide($request, ApprovalDecision::reject($actor, $comment, $metadata));
    }

    /**
     * `force` is the admin escape hatch: without it, only the submitter may
     * withdraw their own request.
     */
    public function cancel(ApprovalRequest $request, ?Model $actor = null, ?string $comment = null, bool $force = false): ApprovalRequest
    {
        /** @var CancelRequest $cancel */
        $cancel = $this->container->make(CancelRequest::class);

        return $cancel($request, $actor, $comment, $force);
    }

    /**
     * Hand an approver's seat to somebody else for the level now waiting. The
     * approver does this themselves; see {@see self::reassign()} for the case
     * where they are not around to.
     */
    public function delegate(
        ApprovalRequest $request,
        Model $approver,
        Model $delegate,
        ?string $comment = null,
    ): ApprovalRequestStepApprover {
        /** @var DelegateApproval $delegateApproval */
        $delegateApproval = $this->container->make(DelegateApproval::class);

        return $delegateApproval($request, $approver, $delegate, $comment);
    }

    /**
     * Rewrite who a seat belongs to. Works on any undecided level, not only the
     * active one.
     */
    public function reassign(
        ApprovalRequest $request,
        ApprovalRequestStepApprover $seat,
        Model $to,
        ?Model $actor = null,
        ?string $comment = null,
    ): ApprovalRequestStepApprover {
        /** @var ReassignApprover $reassign */
        $reassign = $this->container->make(ReassignApprover::class);

        return $reassign($request, $seat, $to, $actor, $comment);
    }

    /**
     * Rebuild a live request's approver seats from the current workflow.
     *
     * @return array<int, array{added: list<string>, removed: list<string>}>
     */
    public function resync(ApprovalRequest $request, ?Model $actor = null, bool $prune = false): array
    {
        /** @var ResyncRequestApprovers $resync */
        $resync = $this->container->make(ResyncRequestApprovers::class);

        return $resync($request, $actor, $prune);
    }

    /**
     * Clear a conflicted request, one way or the other. See
     * {@see ConflictResolution} for what the two ways mean.
     */
    public function resolve(
        ApprovalRequest $request,
        ConflictResolution $resolution,
        ?Model $actor = null,
        ?string $comment = null,
    ): ApprovalRequest {
        /** @var ResolveConflict $resolve */
        $resolve = $this->container->make(ResolveConflict::class);

        return $resolve($request, $resolution, $actor, $comment);
    }

    /**
     * Try again after a rejection, a withdrawal, or a conflict. Everything
     * omitted is inherited from the request being retried.
     *
     * @param  array<string, mixed>|null  $newAttributes
     */
    public function resubmit(
        ApprovalRequest $request,
        ?array $newAttributes = null,
        ?Model $submitter = null,
        ApprovalWorkflow|string|null $workflow = null,
    ): ApprovalRequest {
        /** @var ResubmitRequest $resubmit */
        $resubmit = $this->container->make(ResubmitRequest::class);

        return $resubmit($request, $newAttributes, $submitter, $workflow);
    }

    /**
     * Apply each overdue level's escalation policy. The `approval:escalate`
     * command is the usual caller; this is here for hosts who would rather
     * drive it from their own job.
     *
     * @return array{escalated: list<string>, failed: array<string, string>}
     */
    public function escalateOverdue(): array
    {
        /** @var EscalateOverdueSteps $escalate */
        $escalate = $this->container->make(EscalateOverdueSteps::class);

        return $escalate();
    }

    /**
     * Open requests whose active level is past its deadline, escalated or not.
     *
     * @return Builder<ApprovalRequest>
     */
    public function overdue(): Builder
    {
        return ApprovalRequest::query()->overdue()->orderBy('id');
    }

    /**
     * The inbox query: open requests whose active level is waiting on this
     * person and which they have not already voted on.
     *
     * @return Builder<ApprovalRequest>
     */
    public function awaiting(Model $user): Builder
    {
        return ApprovalRequest::query()->awaitingUser($user)->orderBy('id');
    }

    /**
     * @return Builder<ApprovalRequest>
     */
    public function requestsFor(Model $approvable): Builder
    {
        return ApprovalRequest::query()
            ->forApprovable($approvable)
            ->orderByDesc('id');
    }

    public function pendingFor(Model $approvable): ?ApprovalRequest
    {
        /** @var ApprovalRequest|null */
        return $this->requestsFor($approvable)->pending()->first();
    }

    /**
     * As with the workflow: the call, then the model, then the ambient
     * authenticated user - which is absent in a queue worker, and that is the
     * case worth failing loudly on.
     */
    public function submitterFor(Model $approvable): Model
    {
        if (method_exists($approvable, 'approvalSubmitter')) {
            /** @var mixed $declared */
            $declared = $approvable->approvalSubmitter();

            if ($declared instanceof Model) {
                return $declared;
            }
        }

        return $this->authenticated() ?? throw MissingSubmitterException::forApprovable($approvable);
    }

    private function authenticated(): ?Model
    {
        if (! $this->container->bound('auth')) {
            return null;
        }

        $user = Auth::user();

        return $user instanceof Model ? $user : null;
    }

    private function coerceWorkflow(ApprovalWorkflow|string|null $workflow): ?ApprovalWorkflow
    {
        return match (true) {
            $workflow instanceof ApprovalWorkflow => $workflow,
            is_string($workflow) && $workflow !== '' => $this->workflow($workflow),
            default => null,
        };
    }

    private function declaredWorkflow(Model $approvable): ApprovalWorkflow|string|null
    {
        if (! method_exists($approvable, 'approvalWorkflow')) {
            return null;
        }

        /** @var mixed $declared */
        $declared = $approvable->approvalWorkflow();

        return $declared instanceof ApprovalWorkflow || is_string($declared) ? $declared : null;
    }

    /**
     * The `approval.workflows` map, keyed by model class. Written against the
     * class name rather than the morph alias: config is read by people, and a
     * morph alias is a database detail.
     */
    private function mappedWorkflow(Model $approvable): ?string
    {
        /** @var mixed $map */
        $map = config('approval.workflows');

        if (! is_array($map)) {
            return null;
        }

        /** @var mixed $slug */
        $slug = $map[$approvable::class] ?? $map[$approvable->getMorphClass()] ?? null;

        return is_string($slug) && $slug !== '' ? $slug : null;
    }
}
