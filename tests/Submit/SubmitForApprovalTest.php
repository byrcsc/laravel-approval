<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Enums\ApproverSource;
use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Enums\StageStatus;
use ByRcsc\LaravelApproval\Events\ApprovalSubmitted;
use ByRcsc\LaravelApproval\Exceptions\ApprovalInProgressException;
use ByRcsc\LaravelApproval\Exceptions\InvalidDraftException;
use ByRcsc\LaravelApproval\Exceptions\InvalidWorkflowException;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStage;
use ByRcsc\LaravelApproval\Tests\Stubs\DeletingApprovableResolver;
use ByRcsc\LaravelApproval\Tests\Stubs\Post;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;

it('opens a pending request at the first stage', function (): void {
    $author = user('Grace Hopper');
    $editor = user('Ada Lovelace');
    $post = post();

    $workflow = workflow([
        ['name' => 'Editorial', 'approvers' => [$editor]],
    ]);

    $request = $post->submitForApproval($workflow, null, $author);

    expect($request->state)->toBe(RequestState::Pending)
        ->and($request->getAttributeValue('current_stage_sequence'))->toBe(1)
        ->and($request->getAttributeValue('ulid'))->toBeString()
        ->and($request->submitted_at)->not->toBeNull()
        ->and($request->completed_at)->toBeNull()
        ->and($request->approvable)->toBeInstanceOf(Post::class)
        ->and($request->approvable?->getKey())->toBe($post->getKey())
        ->and($request->submitter?->getKey())->toBe($author->getKey())
        ->and($request->workflow?->getKey())->toBe($workflow->getKey());
});

it('freezes every stage onto the request as a step', function (): void {
    $first = user('First Approver');
    $second = user('Second Approver');

    $workflow = workflow([
        ['name' => 'Team lead', 'required_approvals' => 1, 'sla_minutes' => 60, 'approvers' => [$first]],
        ['name' => 'Finance', 'required_approvals' => null, 'approvers' => [$first, $second]],
    ]);

    $request = post()->submitForApproval($workflow, null, user());

    $stages = $request->stages()->get();

    expect($stages)->toHaveCount(2)
        ->and($stages[0]->getAttributeValue('name'))->toBe('Team lead')
        ->and($stages[0]->getAttributeValue('required_approvals'))->toBe(1)
        ->and($stages[0]->getAttributeValue('sla_minutes'))->toBe(60)
        ->and($stages[1]->getAttributeValue('name'))->toBe('Finance')
        ->and($stages[1]->getAttributeValue('required_approvals'))->toBeNull();
});

it('activates only the first step and queues the rest', function (): void {
    $approver = user();

    $workflow = workflow([
        ['approvers' => [$approver]],
        ['approvers' => [$approver]],
        ['approvers' => [$approver]],
    ]);

    $request = post()->submitForApproval($workflow, null, user());

    expect($request->stages()->get()->pluck('state')->all())->toBe([
        StageStatus::Active,
        StageStatus::Queued,
        StageStatus::Queued,
    ]);
});

it('starts the SLA clock only on the step that is somebody turn', function (): void {
    Date::setTestNow('2026-07-28 09:00:00');

    $approver = user();

    $workflow = workflow([
        ['sla_minutes' => 90, 'approvers' => [$approver]],
        ['sla_minutes' => 30, 'approvers' => [$approver]],
    ]);

    $stages = post()->submitForApproval($workflow, null, user())->stages()->get();

    expect($stages[0]->deadline_at?->toDateTimeString())->toBe('2026-07-28 10:30:00')
        ->and($stages[1]->deadline_at)->toBeNull();

    Date::setTestNow();
});

it('leaves the deadline empty when a stage has no SLA', function (): void {
    $workflow = workflow([['approvers' => [user()]]]);

    $stage = post()->submitForApproval($workflow, null, user())->stages()->sole();

    expect($stage->deadline_at)->toBeNull();
});

it('materializes concrete approvers into assignments', function (): void {
    $one = user('One');
    $two = user('Two');

    $workflow = workflow([
        ['required_approvals' => 2, 'approvers' => [$one, $two]],
    ]);

    $assignments = post()->submitForApproval($workflow, null, user())->stages()->sole()->assignments()->get();

    expect($assignments)->toHaveCount(2)
        ->and($assignments->pluck('approver_id')->all())->toBe([$one->getKey(), $two->getKey()])
        ->and($assignments[0]->source)->toBe(ApproverSource::Assigned)
        ->and($assignments[0]->approver?->getKey())->toBe($one->getKey())
        ->and($assignments[0]->delegatedTo)->toBeNull();
});

it('counts a person named twice as one assignment', function (): void {
    $approver = user();

    $workflow = workflow([
        ['approvers' => [$approver, $approver]],
    ]);

    expect(post()->submitForApproval($workflow, null, user())->stages()->sole()->assignments()->count())->toBe(1);
});

it('records the submission in the audit trail', function (): void {
    $author = user('Author');
    $workflow = workflow([['approvers' => [user()]]], ['slug' => 'editorial']);

    $request = post()->submitForApproval($workflow, null, $author);

    $action = $request->actions()->sole();

    expect($action->getAttributeValue('action')->value)->toBe('submitted')
        ->and($action->actor?->getKey())->toBe($author->getKey())
        ->and($action->getAttributeValue('stage_id'))->toBeNull()
        ->and($action->getAttributeValue('metadata'))->toBe(['workflow' => 'editorial']);
});

it('announces the submission with the step that is waiting', function (): void {
    Event::fake([ApprovalSubmitted::class]);

    $workflow = workflow([['name' => 'Editorial', 'approvers' => [user()]]]);
    $request = post()->submitForApproval($workflow, null, user());

    Event::assertDispatched(ApprovalSubmitted::class, function (ApprovalSubmitted $event) use ($request): bool {
        return $event->request->is($request)
            && $event->stage instanceof ApprovalRequestStage
            && $event->stage->getAttributeValue('name') === 'Editorial';
    });
});

it('refuses a second request while one is still open', function (): void {
    $post = post();
    $workflow = workflow([['approvers' => [user()]]]);

    $first = $post->submitForApproval($workflow, null, user());

    expect(fn () => $post->submitForApproval($workflow, null, user()))
        ->toThrow(ApprovalInProgressException::class, 'already has an unresolved approval request');

    expect(ApprovalRequest::query()->count())->toBe(1)
        ->and($post->pendingApprovalRequest()?->is($first))->toBeTrue();
});

it('allows a fresh request once the open one has closed', function (): void {
    $post = post();
    $workflow = workflow([['approvers' => [user()]]]);

    $first = $post->submitForApproval($workflow, null, user());
    $first->forceFill(['state' => RequestState::Rejected])->save();

    $second = $post->submitForApproval($workflow, null, user());

    expect($second->is($first))->toBeFalse()
        ->and(ApprovalRequest::query()->count())->toBe(2);
});

it('refuses to gate a record that has never been saved', function (): void {
    $workflow = workflow([['approvers' => [user()]]]);

    expect(fn () => (new Post(['title' => 'Unsaved']))->submitForApproval($workflow, null, user()))
        ->toThrow(InvalidDraftException::class, 'has not been saved');
});

it('writes nothing when the approvable disappears while submission is being planned', function (): void {
    $workflow = workflow([[
        'approvers' => [['resolver' => DeletingApprovableResolver::class]],
    ]]);

    expect(fn () => post()->submitForApproval($workflow, null, user('Author')))
        ->toThrow(InvalidDraftException::class, 'no longer exists');

    expect(ApprovalRequest::query()->count())->toBe(0);
});

it('writes nothing when a later stage turns out to be broken', function (): void {
    $workflow = workflow([
        ['approvers' => [user()]],
        ['approvers' => []],
    ]);

    expect(fn () => post()->submitForApproval($workflow, null, user()))
        ->toThrow(InvalidWorkflowException::class);

    expect(ApprovalRequest::query()->count())->toBe(0)
        ->and(ApprovalRequestStage::query()->count())->toBe(0);
});

it('reports the pending request back through the trait', function (): void {
    $post = post();
    $workflow = workflow([['approvers' => [user()]]]);

    expect($post->isPendingApproval())->toBeFalse();

    $request = $post->submitForApproval($workflow, null, user());

    expect($post->isPendingApproval())->toBeTrue()
        ->and($post->pendingApprovalRequest()?->is($request))->toBeTrue()
        ->and($post->latestApprovalRequest()->first()?->is($request))->toBeTrue()
        ->and($post->approvalRequests()->count())->toBe(1);
});

it('reaches the same submit path through the facade', function (): void {
    $post = post();
    $workflow = workflow([['approvers' => [user()]]]);

    $request = Approval::submit($post, $workflow, null, user());

    expect(Approval::pendingFor($post)?->is($request))->toBeTrue()
        ->and(Approval::requestsFor($post)->count())->toBe(1);
});
