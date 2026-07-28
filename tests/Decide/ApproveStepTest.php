<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Enums\StepState;
use ByRcsc\LaravelApproval\Events\ApprovalCompleted;
use ByRcsc\LaravelApproval\Events\LevelCompleted;
use ByRcsc\LaravelApproval\Events\StepApproved;
use ByRcsc\LaravelApproval\Facades\Approval;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;

it('carries a one-approver level with one vote', function (): void {
    $approver = user('Approver');
    $workflow = workflow([['approvers' => [$approver]]]);
    $request = post()->submitForApproval($workflow, null, user());

    Approval::approve($request, $approver);

    expect($request->refresh()->state)->toBe(RequestState::Approved)
        ->and($request->completed_at)->not->toBeNull()
        ->and($request->steps()->sole()->state)->toBe(StepState::Approved);
});

it('holds the level open until the quorum is met', function (): void {
    $one = user('One');
    $two = user('Two');
    $three = user('Three');

    $workflow = workflow([['required_approvals' => 2, 'approvers' => [$one, $two, $three]]]);
    $request = post()->submitForApproval($workflow, null, user());

    Approval::approve($request, $one);

    expect($request->refresh()->state)->toBe(RequestState::Pending)
        ->and($request->steps()->sole()->state)->toBe(StepState::Active);

    Approval::approve($request, $two);

    expect($request->refresh()->state)->toBe(RequestState::Approved);
});

it('reads a null quorum as every seat', function (): void {
    $one = user('One');
    $two = user('Two');

    $workflow = workflow([['required_approvals' => null, 'approvers' => [$one, $two]]]);
    $request = post()->submitForApproval($workflow, null, user());

    Approval::approve($request, $one);
    expect($request->refresh()->state)->toBe(RequestState::Pending);

    Approval::approve($request, $two);
    expect($request->refresh()->state)->toBe(RequestState::Approved);
});

it('hands the request to the next level rather than finishing it', function (): void {
    $first = user('First');
    $second = user('Second');

    $workflow = workflow([
        ['name' => 'Team lead', 'approvers' => [$first]],
        ['name' => 'Finance', 'approvers' => [$second]],
    ]);

    $request = post()->submitForApproval($workflow, null, user());

    Approval::approve($request, $first);

    $steps = $request->refresh()->steps()->get();

    expect($request->state)->toBe(RequestState::Pending)
        ->and($request->getAttributeValue('current_level_sequence'))->toBe(2)
        ->and($steps[0]->state)->toBe(StepState::Approved)
        ->and($steps[1]->state)->toBe(StepState::Active);
});

it('starts the next level clock only when it becomes active', function (): void {
    Date::setTestNow('2026-07-28 09:00:00');

    $first = user('First');
    $second = user('Second');

    $workflow = workflow([
        ['approvers' => [$first]],
        ['sla_minutes' => 60, 'approvers' => [$second]],
    ]);

    $request = post()->submitForApproval($workflow, null, user());

    expect($request->steps()->get()[1]->deadline_at)->toBeNull();

    Date::setTestNow('2026-07-28 14:00:00');
    Approval::approve($request, $first);

    expect($request->steps()->get()[1]->deadline_at?->toDateTimeString())->toBe('2026-07-28 15:00:00');

    Date::setTestNow();
});

it('records every vote in the audit trail', function (): void {
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
        ->and($approvals[0]->getAttributeValue('step_id'))->toBe($request->steps()->sole()->getKey())
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

it('announces each vote with where the count stands', function (): void {
    Event::fake([StepApproved::class, LevelCompleted::class, ApprovalCompleted::class]);

    $one = user('One');
    $two = user('Two');

    $workflow = workflow([['required_approvals' => 2, 'approvers' => [$one, $two]]]);
    $request = post()->submitForApproval($workflow, null, user());

    Approval::approve($request, $one);

    Event::assertDispatched(StepApproved::class, fn (StepApproved $e): bool => $e->votes === 1 && $e->quorum === 2);
    Event::assertNotDispatched(LevelCompleted::class);

    Approval::approve($request, $two);

    Event::assertDispatched(StepApproved::class, fn (StepApproved $e): bool => $e->votes === 2 && $e->quorum === 2);
    Event::assertDispatched(LevelCompleted::class, fn (LevelCompleted $e): bool => $e->next === null);
    Event::assertDispatched(ApprovalCompleted::class);
});

it('announces the level that is waiting next', function (): void {
    Event::fake([LevelCompleted::class, ApprovalCompleted::class]);

    $first = user('First');
    $workflow = workflow([
        ['name' => 'One', 'approvers' => [$first]],
        ['name' => 'Two', 'approvers' => [user('Second')]],
    ]);

    $request = post()->submitForApproval($workflow, null, user());
    Approval::approve($request, $first);

    Event::assertDispatched(LevelCompleted::class, function (LevelCompleted $e): bool {
        return $e->step->getAttributeValue('name') === 'One'
            && $e->next?->getAttributeValue('name') === 'Two';
    });

    Event::assertNotDispatched(ApprovalCompleted::class);
});

it('leaves the record alone until the last level carries', function (): void {
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
