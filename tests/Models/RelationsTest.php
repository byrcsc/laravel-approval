<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Enums\ApproverSource;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflow;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflowLevel;
use ByRcsc\LaravelApproval\Tests\Stubs\Post;
use ByRcsc\LaravelApproval\Tests\Stubs\User;

it('walks a workflow down to its approvers', function () {
    $workflow = workflow([['name' => 'Manager'], ['name' => 'Finance']]);
    $approver = user('Grace Hopper');

    $workflow->levels()->first()?->approvers()->create([
        'approver_type' => $approver->getMorphClass(),
        'approver_id' => $approver->getKey(),
    ]);

    $workflow = ApprovalWorkflow::with('levels.approvers.approver')->find($workflow->getKey());

    expect($workflow?->levels)->toHaveCount(2)
        ->and($workflow?->levels->first()?->approvers)->toHaveCount(1)
        ->and($workflow?->levels->first()?->approvers->first()?->approver)
        ->toBeInstanceOf(User::class);
});

it('orders levels by sequence rather than by insertion', function () {
    $workflow = workflow();

    $workflow->levels()->create(['sequence' => 3, 'name' => 'Director']);
    $workflow->levels()->create(['sequence' => 1, 'name' => 'Manager']);
    $workflow->levels()->create(['sequence' => 2, 'name' => 'Finance']);

    expect($workflow->levels()->pluck('name')->all())
        ->toBe(['Manager', 'Finance', 'Director']);
});

it('reads a level back to its workflow', function () {
    $workflow = workflow([['name' => 'Manager']]);

    /** @var ApprovalWorkflowLevel $level */
    $level = $workflow->levels()->first();

    expect($level->workflow)->toBeInstanceOf(ApprovalWorkflow::class)
        ->and($level->workflow?->getKey())->toBe($workflow->getKey());
});

it('points a request at what it is approving and who asked', function () {
    $post = post('Quarterly report');
    $submitter = user('Alan Turing');

    $request = approvalRequest($post, $submitter);

    expect($request->approvable)->toBeInstanceOf(Post::class)
        ->and($request->approvable?->getKey())->toBe($post->getKey())
        ->and($request->submitter)->toBeInstanceOf(User::class)
        ->and($request->submitter?->getKey())->toBe($submitter->getKey());
});

it('walks a request down to its steps and approver slots', function () {
    $request = approvalRequest();
    $approver = user('Katherine Johnson');

    $step = $request->steps()->create(['sequence' => 1, 'name' => 'Manager']);
    $step->approverSlots()->create([
        'approver_type' => $approver->getMorphClass(),
        'approver_id' => $approver->getKey(),
        'source' => ApproverSource::Resolved,
    ]);

    $request = ApprovalRequest::with('steps.approverSlots.approver')->find($request->getKey());

    expect($request?->steps)->toHaveCount(1)
        ->and($request?->steps->first()?->approverSlots)->toHaveCount(1)
        ->and($request?->steps->first()?->approverSlots->first()?->approver)
        ->toBeInstanceOf(User::class);
});

it('reads a delegated slot back to both people', function () {
    $request = approvalRequest();
    $step = $request->steps()->create(['sequence' => 1, 'name' => 'Manager']);

    $approver = user('Original Approver');
    $delegate = user('Standing In');

    $slot = $step->approverSlots()->create([
        'approver_type' => $approver->getMorphClass(),
        'approver_id' => $approver->getKey(),
        'delegated_to_type' => $delegate->getMorphClass(),
        'delegated_to_id' => $delegate->getKey(),
        'source' => ApproverSource::Delegated,
    ]);

    $slot->refresh();

    // Who was asked survives the delegation.
    expect($slot->approver?->getKey())->toBe($approver->getKey())
        ->and($slot->delegatedTo?->getKey())->toBe($delegate->getKey());
});

it('leaves the delegate empty until somebody delegates', function () {
    $request = approvalRequest();
    $step = $request->steps()->create(['sequence' => 1, 'name' => 'Manager']);
    $approver = user();

    $slot = $step->approverSlots()->create([
        'approver_type' => $approver->getMorphClass(),
        'approver_id' => $approver->getKey(),
    ]);

    expect($slot->delegatedTo)->toBeNull();
});

it('hangs the audit trail off both the request and the step', function () {
    $request = approvalRequest();
    $step = $request->steps()->create(['sequence' => 1, 'name' => 'Manager']);
    $actor = user('Auditor');

    $request->actions()->create(['action' => 'submitted', 'hash' => str_repeat('0', 64)]);
    $request->actions()->create([
        'action' => 'approved',
        'step_id' => $step->getKey(),
        'actor_type' => $actor->getMorphClass(),
        'actor_id' => $actor->getKey(),
        'hash' => str_repeat('0', 64),
    ]);

    expect($request->actions()->count())->toBe(2)
        ->and($step->actions()->count())->toBe(1)
        ->and($step->actions()->first()?->actor?->getKey())->toBe($actor->getKey());
});

it('leaves the actor empty for actions nobody took', function () {
    $request = approvalRequest();

    $action = $request->actions()->create(['action' => 'applied', 'hash' => str_repeat('0', 64)]);

    expect($action->actor)->toBeNull()
        ->and($action->request?->getKey())->toBe($request->getKey())
        ->and($action->step)->toBeNull();
});

it('chains a resubmission back to the request it replaced', function () {
    $post = post();
    $rejected = approvalRequest($post, attributes: ['state' => RequestState::Rejected]);
    $replacement = approvalRequest($post, attributes: ['resubmitted_from_id' => $rejected->getKey()]);

    expect($replacement->resubmittedFrom?->getKey())->toBe($rejected->getKey())
        ->and($rejected->resubmissions)->toHaveCount(1)
        ->and($rejected->resubmissions->first()?->getKey())->toBe($replacement->getKey());
});

it('finds the open requests for one record', function () {
    $post = post('Under review');
    $other = post('Someone else');

    approvalRequest($post);
    approvalRequest($post, attributes: ['state' => RequestState::Rejected]);
    approvalRequest($other);

    $pending = ApprovalRequest::query()->forApprovable($post)->pending()->get();

    expect($pending)->toHaveCount(1)
        ->and($pending->first()?->state)->toBe(RequestState::Pending);
});
