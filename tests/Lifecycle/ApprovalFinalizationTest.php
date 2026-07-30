<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Events\ApprovalApproved;
use ByRcsc\LaravelApproval\Events\ApprovalCompleted;
use ByRcsc\LaravelApproval\Events\DraftApplied;
use ByRcsc\LaravelApproval\Events\DraftConflicted;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Notifications\ApprovalApprovedNotification;
use ByRcsc\LaravelApproval\Notifications\ApprovalConflictNotification;
use ByRcsc\LaravelApproval\Tests\Stubs\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

it('separates routing completion from final request approval', function (): void {
    $observed = [];
    Event::listen(ApprovalCompleted::class, function (ApprovalCompleted $event) use (&$observed): void {
        $observed[] = ['routing', $event->request->state];
    });
    Event::listen(DraftApplied::class, function (DraftApplied $event) use (&$observed): void {
        $observed[] = ['applied', $event->approvable->fresh()?->title];
    });
    Event::listen(ApprovalApproved::class, function (ApprovalApproved $event) use (&$observed): void {
        $observed[] = ['approved', $event->request->state];
    });

    $approver = user('Approver');
    $post = post('Original');
    $request = $post->submitForApproval(
        workflow([['approvers' => [$approver]]]),
        ['title' => 'Approved'],
        user('Requester'),
    );

    Approval::approve($request, $approver);

    expect($observed)->toBe([
        ['routing', RequestState::Pending],
        ['applied', 'Approved'],
        ['approved', RequestState::Approved],
    ]);
});

it('emits conflict behavior without final approval behavior', function (): void {
    Event::fake([ApprovalCompleted::class, ApprovalApproved::class, DraftApplied::class, DraftConflicted::class]);
    $approver = user('Approver');
    $post = post('Original');
    $request = $post->submitForApproval(
        workflow([['approvers' => [$approver]]]),
        ['title' => 'Approved edit'],
        user('Requester'),
    );
    $post->forceFill(['title' => 'Concurrent edit'])->save();

    Approval::approve($request, $approver);

    expect($request->refresh()->state)->toBe(RequestState::Conflicted);

    Event::assertDispatched(ApprovalCompleted::class);
    Event::assertDispatched(DraftConflicted::class);
    Event::assertNotDispatched(DraftApplied::class);
    Event::assertNotDispatched(ApprovalApproved::class);
});

it('notifies conflicts as unresolved and never sends an approved notification', function (): void {
    test()->rebootWith(['approval.notifications.enabled' => true]);
    Notification::fake();
    $requester = user('Requester');
    $approver = user('Approver');
    $post = post('Original');
    $request = $post->submitForApproval(
        workflow([['approvers' => [$approver]]]),
        ['title' => 'Approved edit'],
        $requester,
    );
    $post->forceFill(['title' => 'Concurrent edit'])->save();

    Approval::approve($request, $approver);

    Notification::assertSentTo(
        $requester,
        ApprovalConflictNotification::class,
        fn (ApprovalConflictNotification $notification): bool => $notification->toArray(new User) === [
            'request' => $request->ulid,
            'state' => 'conflicted',
            'subject' => 'Concurrent edit',
        ],
    );
    Notification::assertNotSentTo($requester, ApprovalApprovedNotification::class);
});
