<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Enums\ApproverSource;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflow;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflowStage;
use ByRcsc\LaravelApproval\Tests\Stubs\Post;
use ByRcsc\LaravelApproval\Tests\Stubs\User;

it('walks a workflow down to its approvers', function () {
    $workflow = workflow([['name' => 'Manager'], ['name' => 'Finance']]);
    $approver = user('Grace Hopper');

    $workflow->stages()->first()?->approvers()->create([
        'approver_type' => $approver->getMorphClass(),
        'approver_id' => $approver->getKey(),
    ]);

    $workflow = ApprovalWorkflow::with('stages.approvers.approver')->find($workflow->getKey());

    expect($workflow?->stages)->toHaveCount(2)
        ->and($workflow?->stages->first()?->approvers)->toHaveCount(1)
        ->and($workflow?->stages->first()?->approvers->first()?->approver)
        ->toBeInstanceOf(User::class);
});

it('orders stages by sequence rather than by insertion', function () {
    $workflow = workflow();

    $workflow->stages()->create(['sequence' => 3, 'name' => 'Director']);
    $workflow->stages()->create(['sequence' => 1, 'name' => 'Manager']);
    $workflow->stages()->create(['sequence' => 2, 'name' => 'Finance']);

    expect($workflow->stages()->pluck('name')->all())
        ->toBe(['Manager', 'Finance', 'Director']);
});

it('reads a stage back to its workflow', function () {
    $workflow = workflow([['name' => 'Manager']]);

    /** @var ApprovalWorkflowStage $stage */
    $stage = $workflow->stages()->first();

    expect($stage->workflow)->toBeInstanceOf(ApprovalWorkflow::class)
        ->and($stage->workflow?->getKey())->toBe($workflow->getKey());
});

it('points a request at what it is approving and who asked', function () {
    $post = post('Quarterly report');
    $requester = user('Alan Turing');

    $request = approvalRequest($post, $requester);

    expect($request->approvable)->toBeInstanceOf(Post::class)
        ->and($request->approvable?->getKey())->toBe($post->getKey())
        ->and($request->requester)->toBeInstanceOf(User::class)
        ->and($request->requester?->getKey())->toBe($requester->getKey());
});

it('walks a request down to its steps and approver assignments', function () {
    $request = approvalRequest();
    $approver = user('Katherine Johnson');

    $stage = $request->stages()->create(['sequence' => 1, 'name' => 'Manager']);
    $stage->assignments()->create([
        'approver_type' => $approver->getMorphClass(),
        'approver_id' => $approver->getKey(),
        'source' => ApproverSource::Resolved,
    ]);

    $request = ApprovalRequest::with('stages.assignments.approver')->find($request->getKey());

    expect($request?->stages)->toHaveCount(1)
        ->and($request?->stages->first()?->assignments)->toHaveCount(1)
        ->and($request?->stages->first()?->assignments->first()?->approver)
        ->toBeInstanceOf(User::class);
});

it('reads a delegated assignment back to both people', function () {
    $request = approvalRequest();
    $stage = $request->stages()->create(['sequence' => 1, 'name' => 'Manager']);

    $approver = user('Original Approver');
    $delegate = user('Standing In');

    $assignment = $stage->assignments()->create([
        'approver_type' => $approver->getMorphClass(),
        'approver_id' => $approver->getKey(),
        'delegated_to_type' => $delegate->getMorphClass(),
        'delegated_to_id' => $delegate->getKey(),
        'source' => ApproverSource::Delegated,
    ]);

    $assignment->refresh();

    // Who was asked survives the delegation.
    expect($assignment->approver?->getKey())->toBe($approver->getKey())
        ->and($assignment->delegatedTo?->getKey())->toBe($delegate->getKey());
});

it('leaves the delegate empty until somebody delegates', function () {
    $request = approvalRequest();
    $stage = $request->stages()->create(['sequence' => 1, 'name' => 'Manager']);
    $approver = user();

    $assignment = $stage->assignments()->create([
        'approver_type' => $approver->getMorphClass(),
        'approver_id' => $approver->getKey(),
    ]);

    expect($assignment->delegatedTo)->toBeNull();
});

it('hangs the audit trail off both the request and the step', function () {
    $request = approvalRequest();
    $stage = $request->stages()->create(['sequence' => 1, 'name' => 'Manager']);
    $actor = user('Auditor');

    $request->actions()->create(['action' => 'submitted', 'hash' => str_repeat('0', 64)]);
    $request->actions()->create([
        'action' => 'approved',
        'stage_id' => $stage->getKey(),
        'actor_type' => $actor->getMorphClass(),
        'actor_id' => $actor->getKey(),
        'hash' => str_repeat('0', 64),
    ]);

    expect($request->actions()->count())->toBe(2)
        ->and($stage->actions()->count())->toBe(1)
        ->and($stage->actions()->first()?->actor?->getKey())->toBe($actor->getKey());
});

it('leaves the actor empty for actions nobody took', function () {
    $request = approvalRequest();

    $action = $request->actions()->create(['action' => 'applied', 'hash' => str_repeat('0', 64)]);

    expect($action->actor)->toBeNull()
        ->and($action->request?->getKey())->toBe($request->getKey())
        ->and($action->stage)->toBeNull();
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
