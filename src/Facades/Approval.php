<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Facades;

use ByRcsc\LaravelApproval\ApprovalManager;
use ByRcsc\LaravelApproval\Data\ApprovalDecision;
use ByRcsc\LaravelApproval\Enums\ConflictResolution;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStepApprover;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;

/**
 * @method static ApprovalWorkflow|null findWorkflow(string $slug)
 * @method static ApprovalWorkflow workflow(string $slug)
 * @method static ApprovalWorkflow workflowFor(Model $approvable, ApprovalWorkflow|string|null $given = null)
 * @method static ApprovalRequest submit(Model $approvable, ApprovalWorkflow|string|null $workflow = null, array<string, mixed>|null $newAttributes = null, Model|null $submitter = null)
 * @method static ApprovalRequest decide(ApprovalRequest $request, ApprovalDecision $decision)
 * @method static ApprovalRequest approve(ApprovalRequest $request, Model $actor, string|null $comment = null, array<string, mixed> $metadata = [])
 * @method static ApprovalRequest reject(ApprovalRequest $request, Model $actor, string $comment, array<string, mixed> $metadata = [])
 * @method static ApprovalRequest returnToSubmitter(ApprovalRequest $request, Model $actor, string $comment, array<string, mixed> $metadata = [])
 * @method static ApprovalRequest cancel(ApprovalRequest $request, Model|null $actor = null, string|null $comment = null, bool $force = false)
 * @method static mixed withoutGuard(Model $approvable, callable $callback, Model|null $actor = null, string|null $comment = null)
 * @method static ApprovalRequestStepApprover delegate(ApprovalRequest $request, Model $approver, Model $delegate, string|null $comment = null)
 * @method static ApprovalRequestStepApprover reassign(ApprovalRequest $request, ApprovalRequestStepApprover $seat, Model $to, Model|null $actor = null, string|null $comment = null)
 * @method static array<int, array{added: list<string>, removed: list<string>}> resync(ApprovalRequest $request, Model|null $actor = null, bool $prune = false)
 * @method static ApprovalRequest resolve(ApprovalRequest $request, ConflictResolution $resolution, Model|null $actor = null, string|null $comment = null)
 * @method static ApprovalRequest resubmit(ApprovalRequest $request, array<string, mixed>|null $newAttributes = null, Model|null $submitter = null, ApprovalWorkflow|string|null $workflow = null)
 * @method static ApprovalRequest resume(ApprovalRequest $request, array<string, mixed>|null $changes = null, ApprovalWorkflow|string|null $workflow = null)
 * @method static array{escalated: list<string>, reminded: list<string>, stranded: list<string>, failed: array<string, string>} escalateOverdue()
 * @method static Builder<ApprovalRequest> overdue()
 * @method static Builder<ApprovalRequest> awaiting(Model $user)
 * @method static Builder<ApprovalRequest> requestsFor(Model $approvable)
 * @method static ApprovalRequest|null pendingFor(Model $approvable)
 * @method static Model submitterFor(Model $approvable)
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
