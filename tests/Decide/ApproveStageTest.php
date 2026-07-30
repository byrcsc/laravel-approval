<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Enums\StageStatus;
use ByRcsc\LaravelApproval\Events\ApprovalCompleted;
use ByRcsc\LaravelApproval\Events\StageCompleted;
use ByRcsc\LaravelApproval\Events\StageApprovalRecorded;
use ByRcsc\LaravelApproval\Facades\Approval;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;

it('carries a one-approver stage with one decision', function (): void {
    $approver = user('Approver');
    $workflow = workflow([['approvers' => [$approver]]]);
    $request = post()->submitForApproval($workflow, null, user());

    Approval::approve($request, $approver);

    expect($request->refresh()->state)->toBe(RequestState::Approved)
        ->and($request->completed_at)->not->toBeNull()
        ->and($request->stages()->sole()->state)->toBe(StageStatus::Approved);
});

it('holds the stage open until the required approvals is met', function (): void {
    $one = user('One');
    $two = user('Two');
    $three = user('Three');

    $workflow = workflow([['required_approvals' => 2, 'approvers' => [$one, $two, $three]]]);
    $request = post()->submitForApproval($workflow, null, user());

    Approval::approve($request, $one);

    expect($request->refresh()->state)->toBe(RequestState::Pending)
        ->and($request->stages()->sole()->state)->toBe(StageStatus::Active);

    Approval::approve($request, $two);

    expect($request->refresh()->state)->toBe(RequestState::Approved);
});

it('reads a null required approvals as every assignment', function (): void {
    $one = user('One');
    $two = user('Two');

    $workflow = workflow([['required_approvals' => null, 'approvers' => [$one, $two]]]);
    $request = post()->submitForApproval($workflow, null, user());

    Approval::approve($request, $one);
    expect($request->refresh()->state)->toBe(RequestState::Pending);

    Approval::approve($request, $two);
    expect($request->refresh()->state)->toBe(RequestState::Approved);
});

it('hands the request to the next stage rather than finishing it', function (): void {
    $first = user('First');
    $second = user('Second');

    $workflow = workflow([
        ['name' => 'Team lead', 'approvers' => [$first]],
        ['name' => 'Finance', 'approvers' => [$second]],
    ]);

    $request = post()->submitForApproval($workflow, null, user());

    Approval::approve($request, $first);

    $stages = $request->refresh()->stages()->get();

    expect($request->state)->toBe(RequestState::Pending)
        ->and($request->getAttributeValue('current_stage_sequence'))->toBe(2)
        ->and($stages[0]->state)->toBe(StageStatus::Approved)
        ->and($stages[1]->state)->toBe(StageStatus::Active);
});

it('starts the next stage clock only when it becomes active', function (): void {
    Date::setTestNow('2026-07-28 09:00:00');

    $first = user('First');
    $second = user('Second');

    $workflow = workflow([
        ['approvers' => [$first]],
        ['sla_minutes' => 60, 'approvers' => [$second]],
    ]);

    $request = post()->submitForApproval($workflow, null, user());

    expect($request->stages()->get()[1]->deadline_at)->toBeNull();

    Date::setTestNow('2026-07-28 14:00:00');
    Approval::approve($request, $first);

    expect($request->stages()->get()[1]->deadline_at?->toDateTimeString())->toBe('2026-07-28 15:00:00');

    Date::setTestNow();
});

it('records every decision in the audit trail', function (): void {
    $one = user('One');
    $two = user('Two');

    $workflow = workflow([['required_approvals' => 2, 'approvers' => [$one, $two]]]);
    $request = post()->submitForApproval($workflow, null, user());

    Approval::approve($request, $one, 'Looks right to me.');
    Approval::approve($request, $two);

    $approvals = $request->actions()->where('action', 'approved')->get();

    expect($approvals)->toHaveCount(2)
        ->and($approvals[0]->getAttributeValue('comment'))->toBe('Looks right to me.')
        ->and($approvals[0]->actor?->getKey())->toBe($one->getKey())
        ->and($approvals[0]->getAttributeValue('stage_id'))->toBe($request->stages()->sole()->getKey())
        ->and($approvals[1]->getAttributeValue('comment'))->toBeNull();
});

it('carries decision metadata onto the audit action', function (): void {
    $approver = user();
    $workflow = workflow([['approvers' => [$approver]]]);
    $request = post()->submitForApproval($workflow, null, user());

    Approval::approve($request, $approver, null, ['ip' => '10.0.0.1']);

    expect($request->actions()->where('action', 'approved')->sole()->getAttributeValue('metadata'))
        ->toBe(['ip' => '10.0.0.1']);
});

it('announces each approval with the stage progress', function (): void {
    Event::fake([StageApprovalRecorded::class, StageCompleted::class, ApprovalCompleted::class]);

    $one = user('One');
    $two = user('Two');

    $workflow = workflow([['required_approvals' => 2, 'approvers' => [$one, $two]]]);
    $request = post()->submitForApproval($workflow, null, user());

    Approval::approve($request, $one);

    Event::assertDispatched(
        StageApprovalRecorded::class,
        fn (StageApprovalRecorded $e): bool => $e->approvalsReceived === 1 && $e->approvalsRequired === 2,
    );
    Event::assertNotDispatched(StageCompleted::class);

    Approval::approve($request, $two);

    Event::assertDispatched(
        StageApprovalRecorded::class,
        fn (StageApprovalRecorded $e): bool => $e->approvalsReceived === 2 && $e->approvalsRequired === 2,
    );
    Event::assertDispatched(StageCompleted::class, fn (StageCompleted $e): bool => $e->next === null);
    Event::assertDispatched(ApprovalCompleted::class);
});

it('announces the stage that is waiting next', function (): void {
    Event::fake([StageCompleted::class, ApprovalCompleted::class]);

    $first = user('First');
    $workflow = workflow([
        ['name' => 'One', 'approvers' => [$first]],
        ['name' => 'Two', 'approvers' => [user('Second')]],
    ]);

    $request = post()->submitForApproval($workflow, null, user());
    Approval::approve($request, $first);

    Event::assertDispatched(StageCompleted::class, function (StageCompleted $e): bool {
        return $e->stage->getAttributeValue('name') === 'One'
            && $e->next?->getAttributeValue('name') === 'Two';
    });

    Event::assertNotDispatched(ApprovalCompleted::class);
});

it('leaves the record alone until the last stage carries', function (): void {
    $first = user('First');
    $post = post('Original title');

    $workflow = workflow([
        ['approvers' => [$first]],
        ['approvers' => [user('Second')]],
    ]);

    $request = $post->submitForApproval($workflow, ['title' => 'Revised title'], user());
    Approval::approve($request, $first);

    // The draft lands on final approval, not on the way there.
    expect($post->fresh()?->getAttributeValue('title'))->toBe('Original title');
});
