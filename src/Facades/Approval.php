<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Facades;

use ByRcsc\LaravelApproval\ApprovalManager;
use ByRcsc\LaravelApproval\Data\ApprovalDecision;
use ByRcsc\LaravelApproval\Data\AttachmentEvidence;
use ByRcsc\LaravelApproval\Data\BulkDecisionResult;
use ByRcsc\LaravelApproval\Data\DelegationEligibility;
use ByRcsc\LaravelApproval\Data\Eligibility;
use ByRcsc\LaravelApproval\Data\RevisedDraft;
use ByRcsc\LaravelApproval\Data\WithdrawalEligibility;
use ByRcsc\LaravelApproval\Data\WorkflowSyncResult;
use ByRcsc\LaravelApproval\Enums\ConflictResolution;
use ByRcsc\LaravelApproval\Models\ApprovalAttachment;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestAssignment;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflow;
use ByRcsc\LaravelApproval\Testing\ApprovalFake;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;

/**
 * The package's primary command and query surface.
 *
 * Members are listed in the order the manager declares them: workflow lookup,
 * routine approval commands and queries, then the administrative and
 * operational operations, which a host application should keep behind
 * administrative authorization, a console command, or a scheduled job.
 *
 * @method static ApprovalFake fake()
 * @method static ApprovalWorkflow|null findWorkflow(string $slug)
 * @method static ApprovalWorkflow workflow(string $slug)
 * @method static Builder<ApprovalWorkflow> workflows()
 * @method static Collection<int, ApprovalWorkflow> workflowsFor(Model $approvable, Model|null $requester = null, array<string, mixed>|null $newAttributes = null)
 * @method static ApprovalWorkflow workflowFor(Model $approvable, ApprovalWorkflow|string|null $given = null, Model|null $requester = null, array<string, mixed>|null $newAttributes = null)
 * @method static ApprovalRequest submit(Model $approvable, ApprovalWorkflow|string|null $workflow = null, array<string, mixed>|null $newAttributes = null, Model|null $requester = null, string|null $comment = null, array<string, mixed> $metadata = [])
 * @method static Eligibility eligibility(ApprovalRequest $request, Model $actor)
 * @method static bool canApprove(ApprovalRequest $request, Model $actor)
 * @method static WithdrawalEligibility withdrawalEligibility(ApprovalRequest $request, Model $actor)
 * @method static bool canWithdraw(ApprovalRequest $request, Model $actor)
 * @method static DelegationEligibility delegationEligibility(ApprovalRequest $request, Model $actor, Model|null $delegate = null)
 * @method static bool canDelegate(ApprovalRequest $request, Model $actor, Model|null $delegate = null)
 * @method static ApprovalRequest decide(ApprovalRequest $request, ApprovalDecision $decision)
 * @method static ApprovalRequest approve(ApprovalRequest $request, Model $actor, string|null $comment = null, array<string, mixed> $metadata = [])
 * @method static ApprovalRequest reject(ApprovalRequest $request, Model $actor, string $comment, array<string, mixed> $metadata = [])
 * @method static ApprovalRequest returnForRevision(ApprovalRequest $request, Model $actor, string $comment, array<string, mixed> $metadata = [])
 * @method static BulkDecisionResult decideMany(iterable<int, ApprovalRequest> $requests, callable(ApprovalRequest): ApprovalDecision $decision)
 * @method static BulkDecisionResult approveMany(iterable<int, ApprovalRequest> $requests, Model $actor, string|null $comment = null, array<string, mixed> $metadata = [])
 * @method static BulkDecisionResult rejectMany(iterable<int, ApprovalRequest> $requests, Model $actor, string $comment, array<string, mixed> $metadata = [])
 * @method static ApprovalRequest withdraw(ApprovalRequest $request, Model $actor, string|null $comment = null)
 * @method static ApprovalRequestAssignment delegate(ApprovalRequest $request, Model $approver, Model $delegate, string|null $comment = null)
 * @method static ApprovalRequest reviseAndResubmit(ApprovalRequest $request, RevisedDraft $draft, Model|null $requester = null, ApprovalWorkflow|string|null $workflow = null)
 * @method static ApprovalAttachment attach(AttachmentEvidence $evidence)
 * @method static Builder<ApprovalRequest> overdue()
 * @method static Builder<ApprovalRequest> awaiting(Model $user)
 * @method static Builder<ApprovalRequest> requestsFor(Model $approvable)
 * @method static ApprovalRequest|null pendingFor(Model $approvable)
 * @method static Model requesterFor(Model $approvable)
 * @method static ApprovalRequest cancel(ApprovalRequest $request, Model $actor, string|null $comment = null)
 * @method static ApprovalRequestAssignment reassign(ApprovalRequest $request, ApprovalRequestAssignment $assignment, Model $to, Model $actor, string|null $comment = null)
 * @method static array<int, array{added: list<string>, removed: list<string>}> resync(ApprovalRequest $request, Model $actor, bool $prune = false)
 * @method static ApprovalRequest resolve(ApprovalRequest $request, ConflictResolution $resolution, Model|null $actor = null, string|null $comment = null)
 * @method static mixed withoutGuard(Model $approvable, callable $callback, Model|null $actor = null, string|null $comment = null)
 * @method static WorkflowSyncResult syncDefinitions(iterable<int|string, mixed> $definitions, bool $dryRun = false)
 * @method static list<array{request: string, subject: string, problem: string}> verifyTrail(ApprovalRequest|null $only = null)
 * @method static array{escalated: list<string>, reminded: list<string>, stranded: list<string>, failed: array<string, string>} escalateOverdue()
 *
 * @see ApprovalManager
 */
final class Approval extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ApprovalManager::class;
    }
}
