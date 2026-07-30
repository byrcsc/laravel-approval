<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Events\ApprovalApproved;
use ByRcsc\LaravelApproval\Events\ApprovalCompleted;
use ByRcsc\LaravelApproval\Events\ApprovalRejected;
use ByRcsc\LaravelApproval\Events\ApprovalSubmitted;
use ByRcsc\LaravelApproval\Events\DraftConflicted;
use ByRcsc\LaravelApproval\Events\LevelCompleted;
use ByRcsc\LaravelApproval\Events\StepOverdue;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Notifications\ApprovalApprovedNotification;
use ByRcsc\LaravelApproval\Notifications\ApprovalOverdueNotification;
use ByRcsc\LaravelApproval\Notifications\ApprovalRejectedNotification;
use ByRcsc\LaravelApproval\Notifications\ApprovalRequestedNotification;
use ByRcsc\LaravelApproval\Support\NotificationMap;
use ByRcsc\LaravelApproval\Tests\Stubs\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;

/**
 * Notifications are off unless the host turns them on, so every test that
 * expects one has to say so - which is the behavior under test as much as the
 * messages themselves.
 */
function withNotifications(): void
{
    test()->rebootWith(['approval.notifications.enabled' => true]);
}

describe('opt-in', function (): void {
    it('sends nothing at all by default', function (): void {
        Notification::fake();

        $approver = user('Approver');
        $workflow = workflow([['approvers' => [$approver]]]);
        $request = post()->submitForApproval($workflow, null, user('Author'));

        Approval::approve($request, $approver);

        Notification::assertNothingSent();
    });

    it('registers no listeners while it is off', function (): void {
        expect(NotificationMap::enabled())->toBeFalse()
            ->and(NotificationMap::resolved())->toBe([]);
    });

    it('sends once switched on', function (): void {
        withNotifications();
        Notification::fake();

        $approver = user('Approver');
        $workflow = workflow([['approvers' => [$approver]]]);
        post()->submitForApproval($workflow, null, user('Author'));

        Notification::assertSentTo($approver, ApprovalRequestedNotification::class);
    });

    it('silences a single event mapped to null', function (): void {
        test()->rebootWith([
            'approval.notifications.enabled' => true,
            'approval.notifications.map' => [ApprovalSubmitted::class => null],
        ]);

        Notification::fake();

        $workflow = workflow([['approvers' => [user('Approver')]]]);
        post()->submitForApproval($workflow, null, user('Author'));

        Notification::assertNothingSent();
    });
});

describe('who gets told', function (): void {
    beforeEach(function (): void {
        withNotifications();
        Notification::fake();
    });

    it('asks the approvers of the level now waiting', function (): void {
        $first = user('First');
        $second = user('Second');

        $workflow = workflow([
            ['approvers' => [$first]],
            ['approvers' => [$second]],
        ]);

        $request = post()->submitForApproval($workflow, null, user('Author'));

        Notification::assertSentTo($first, ApprovalRequestedNotification::class);
        Notification::assertNotSentTo($second, ApprovalRequestedNotification::class);

        Approval::approve($request, $first);

        Notification::assertSentTo($second, ApprovalRequestedNotification::class);
    });

    it('asks every seat on a level with a quorum', function (): void {
        $one = user('One');
        $two = user('Two');

        $workflow = workflow([['required_approvals' => 2, 'approvers' => [$one, $two]]]);
        post()->submitForApproval($workflow, null, user('Author'));

        Notification::assertSentTo($one, ApprovalRequestedNotification::class);
        Notification::assertSentTo($two, ApprovalRequestedNotification::class);
    });

    it('asks the delegate rather than the approver who handed the seat over', function (): void {
        $first = user('First');
        $approver = user('Approver');
        $delegate = user('Delegate');

        $workflow = workflow([
            ['approvers' => [$first]],
            ['approvers' => [$approver]],
        ]);

        $request = post()->submitForApproval($workflow, null, user('Author'));

        // Delegate the queued level, then let it become active.
        $seat = $request->steps()->get()[1]->approverSlots()->sole();
        $seat->forceFill([
            'delegated_to_type' => $delegate->getMorphClass(),
            'delegated_to_id' => $delegate->getKey(),
        ])->save();

        Notification::fake();
        Approval::approve($request, $first);

        Notification::assertSentTo($delegate, ApprovalRequestedNotification::class);
        Notification::assertNotSentTo($approver, ApprovalRequestedNotification::class);
    });

    it('tells the submitter when it is approved', function (): void {
        $author = user('Author');
        $approver = user('Approver');

        $workflow = workflow([['approvers' => [$approver]]]);
        $request = post()->submitForApproval($workflow, null, $author);

        Approval::approve($request, $approver);

        Notification::assertSentTo($author, ApprovalApprovedNotification::class);
        Notification::assertNotSentTo($approver, ApprovalApprovedNotification::class);
    });

    it('tells the submitter when it is rejected, with the reason', function (): void {
        $author = user('Author');
        $approver = user('Approver');

        $workflow = workflow([['approvers' => [$approver]]]);
        $request = post()->submitForApproval($workflow, null, $author);

        Approval::reject($request, $approver, 'Missing a purchase order.');

        Notification::assertSentTo(
            $author,
            ApprovalRejectedNotification::class,
            function (ApprovalRejectedNotification $notification): bool {
                return $notification->toArray(new User)['reason'] === 'Missing a purchase order.';
            },
        );
    });

    it('tells the late approvers when a level goes overdue', function (): void {
        Date::setTestNow('2026-07-28 09:00:00');

        $request = overdueRequest();
        $approver = $request->steps()->sole()->approverSlots()->sole()->approver;

        Date::setTestNow('2026-07-28 10:01:00');
        Approval::escalateOverdue();

        Notification::assertSentTo($approver, ApprovalOverdueNotification::class);

        Date::setTestNow();
    });
});

describe('the messages', function (): void {
    beforeEach(function (): void {
        withNotifications();
        Notification::fake();
    });

    it('names the record in the subject line', function (): void {
        $approver = user('Approver');
        $workflow = workflow([['approvers' => [$approver]]]);
        post('Quarterly report')->submitForApproval($workflow, null, user('Author'));

        Notification::assertSentTo(
            $approver,
            ApprovalRequestedNotification::class,
            function (ApprovalRequestedNotification $notification): bool {
                return $notification->toMail(new User)->subject === 'Approval needed: Quarterly report';
            },
        );
    });

    it('falls back to the class and key when the record has no name', function (): void {
        $approver = user('Approver');
        $order = purchaseOrder('PO-9001');
        workflow([['approvers' => [$approver]]], ['slug' => 'purchase-order']);

        $order->submitForApproval(null, null, user('Author'));

        Notification::assertSentTo(
            $approver,
            ApprovalRequestedNotification::class,
            function (ApprovalRequestedNotification $notification): bool {
                return $notification->subject() === 'PO-9001';
            },
        );
    });

    it('carries the request ulid so a message can be traced back', function (): void {
        $approver = user('Approver');
        $workflow = workflow([['approvers' => [$approver]]]);
        $request = post()->submitForApproval($workflow, null, user('Author'));

        Notification::assertSentTo(
            $approver,
            ApprovalRequestedNotification::class,
            function (ApprovalRequestedNotification $notification) use ($request): bool {
                return $notification->toArray(new User)['request'] === $request->getAttributeValue('ulid');
            },
        );
    });

    it('takes its channels from config', function (): void {
        test()->rebootWith([
            'approval.notifications.enabled' => true,
            'approval.notifications.channels' => ['database'],
        ]);
        Notification::fake();

        $approver = user('Approver');
        $workflow = workflow([['approvers' => [$approver]]]);
        post()->submitForApproval($workflow, null, user('Author'));

        Notification::assertSentTo(
            $approver,
            ApprovalRequestedNotification::class,
            function (ApprovalRequestedNotification $notification): bool {
                return $notification->via(new User) === ['database'];
            },
        );
    });
});

describe('transaction safety', function (): void {
    it('sends nothing when the transaction that caused it rolls back', function (): void {
        withNotifications();
        Notification::fake();

        $workflow = workflow([
            ['approvers' => [user('Approver')]],
            // A second level with no approvers makes the whole submit fail
            // after the first level's events would otherwise have fired.
            ['approvers' => []],
        ]);

        rescue(fn () => post()->submitForApproval($workflow, null, user('Author')));

        Notification::assertNothingSent();
    });
});

describe('the map', function (): void {
    it('drops entries pointing at something that is not a notification', function (): void {
        config()->set('approval.notifications.enabled', true);
        config()->set('approval.notifications.map', [ApprovalCompleted::class => stdClass::class]);

        expect(NotificationMap::resolved())->toBe([]);
    });

    it('maps the six events it ships with', function (): void {
        expect(array_keys(NotificationMap::defaults()))->toBe([
            ApprovalSubmitted::class,
            LevelCompleted::class,
            ApprovalApproved::class,
            DraftConflicted::class,
            ApprovalRejected::class,
            StepOverdue::class,
        ]);
    });
});
