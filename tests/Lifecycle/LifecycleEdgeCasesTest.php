<?php

declare(strict_types=1);

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
    $workflow->levels()->delete();

    expect(fn () => Approval::resubmit($request))
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

it('holds a cancelled request immutable except to resubmission', function (): void {
    $approver = user('Approver');
    $submitter = user('Submitter');

    $workflow = workflow([['approvers' => [$approver]]]);
    $request = post()->submitForApproval($workflow, null, $submitter);

    Approval::cancel($request, $submitter);

    expect(fn () => Approval::approve($request, $approver))->toThrow(AlreadyDecidedException::class)
        ->and(fn () => Approval::reject($request, $approver, 'Too late.'))->toThrow(AlreadyDecidedException::class)
        ->and(fn () => Approval::delegate($request, $approver, user('Delegate')))->toThrow(AlreadyDecidedException::class)
        ->and(fn () => Approval::cancel($request, $submitter))->toThrow(AlreadyDecidedException::class)
        ->and(fn () => Approval::resync($request))->toThrow(AlreadyDecidedException::class);

    // Withdrawal is not a verdict; trying again is allowed.
    $second = Approval::resubmit($request);

    expect($second->state)->toBe(RequestState::Pending)
        ->and($second->resubmittedFrom?->is($request))->toBeTrue();
});

it('runs end to end on renamed tables', function (): void {
    /** @var TestCase $this */
    $this->rebootWith(['approval.table_names' => [
        'workflows' => 'sign_off_workflows',
        'levels' => 'sign_off_levels',
        'level_approvers' => 'sign_off_level_approvers',
        'requests' => 'sign_off_requests',
        'steps' => 'sign_off_steps',
        'step_approvers' => 'sign_off_step_approvers',
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
