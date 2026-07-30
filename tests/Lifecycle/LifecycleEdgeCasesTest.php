<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Data\RevisedDraft;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Exceptions\AlreadyDecidedException;
use ByRcsc\LaravelApproval\Exceptions\InvalidWorkflowException;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Tests\TestCase;
use Illuminate\Support\Facades\DB;

/**
 * The definition side of the engine moving underneath the request side: edited
 * workflows, deleted workflows, and requests that closed for reasons other
 * than a decision.
 */
it('refuses a resubmission against a workflow edited down to nothing', function (): void {
    $approver = user('Approver');

    $workflow = workflow([['approvers' => [$approver]]]);
    $request = post()->submitForApproval($workflow, null, user());

    Approval::reject($request, $approver, 'Not yet.');

    // Somebody gutted the workflow while the rejection sat in a backlog. The
    // fresh snapshot a resubmission takes has nothing to copy.
    $workflow->stages()->delete();

    expect(fn () => Approval::reviseAndResubmit($request, RevisedDraft::inherit()))
        ->toThrow(InvalidWorkflowException::class)
        ->and($request->refresh()->state)->toBe(RequestState::Rejected)
        ->and(ApprovalRequest::query()->count())->toBe(1);
});

it('carries an in-flight request whose workflow was soft-deleted', function (): void {
    $first = user('First');
    $second = user('Second');

    $workflow = workflow([
        ['approvers' => [$first]],
        ['approvers' => [$second]],
    ]);

    $post = post('Original title');
    $request = $post->submitForApproval($workflow, ['title' => 'Revised title'], user());

    Approval::approve($request, $first);

    // The workflow is retired mid-flight. The request runs on its snapshot
    // and never notices.
    $workflow->delete();

    Approval::approve($request, $second);

    expect($request->refresh()->state)->toBe(RequestState::Approved)
        ->and($post->fresh()?->getAttributeValue('title'))->toBe('Revised title');
});

it('holds a withdrawn request immutable except to resubmission', function (): void {
    $approver = user('Approver');
    $requester = user('Requester');

    $workflow = workflow([['approvers' => [$approver]]]);
    $request = post()->submitForApproval($workflow, null, $requester);

    Approval::withdraw($request, $requester);

    expect(fn () => Approval::approve($request, $approver))->toThrow(AlreadyDecidedException::class)
        ->and(fn () => Approval::reject($request, $approver, 'Too late.'))->toThrow(AlreadyDecidedException::class)
        ->and(fn () => Approval::delegate($request, $approver, user('Delegate')))->toThrow(AlreadyDecidedException::class)
        ->and(fn () => Approval::withdraw($request, $requester))->toThrow(AlreadyDecidedException::class)
        ->and(fn () => Approval::resync($request, user('Operator')))->toThrow(AlreadyDecidedException::class);

    // Withdrawal is not a verdict; trying again is allowed.
    $second = Approval::reviseAndResubmit($request, RevisedDraft::inherit());

    expect($second->state)->toBe(RequestState::Pending)
        ->and($second->resubmittedFrom?->is($request))->toBeTrue();
});

it('runs end to end on renamed tables', function (): void {
    /** @var TestCase $this */
    $this->rebootWith(['approval.table_names' => [
        'workflows' => 'sign_off_workflows',
        'workflow_stages' => 'sign_off_stages',
        'workflow_stage_approvers' => 'sign_off_stage_approvers',
        'requests' => 'sign_off_requests',
        'request_stages' => 'sign_off_request_stages',
        'assignments' => 'sign_off_assignments',
        'actions' => 'sign_off_actions',
        'attachments' => 'sign_off_attachments',
    ]]);

    $approver = user('Approver');
    $workflow = workflow([['approvers' => [$approver]]]);
    $request = post()->submitForApproval($workflow, null, user());

    Approval::approve($request, $approver);

    expect($request->refresh()->state)->toBe(RequestState::Approved)
        ->and(DB::table('sign_off_requests')->count())->toBe(1)
        ->and(DB::table('sign_off_actions')->where('action', 'approved')->count())->toBe(1);
});
