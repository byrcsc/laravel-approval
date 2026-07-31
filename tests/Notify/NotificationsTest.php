<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Enums\EscalationAction;
use ByRcsc\LaravelApproval\Events\ApprovalApproved;
use ByRcsc\LaravelApproval\Events\ApprovalCompleted;
use ByRcsc\LaravelApproval\Events\ApprovalDelegated;
use ByRcsc\LaravelApproval\Events\ApprovalRejected;
use ByRcsc\LaravelApproval\Events\ApprovalReturned;
use ByRcsc\LaravelApproval\Events\ApprovalSubmitted;
use ByRcsc\LaravelApproval\Events\ApproverReassigned;
use ByRcsc\LaravelApproval\Events\ApproversEscalated;
use ByRcsc\LaravelApproval\Events\ApproversResynced;
use ByRcsc\LaravelApproval\Events\DraftConflicted;
use ByRcsc\LaravelApproval\Events\StageCompleted;
use ByRcsc\LaravelApproval\Events\StageOverdue;
use ByRcsc\LaravelApproval\Events\StageOverdueReminder;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Notifications\ApprovalApprovedNotification;
use ByRcsc\LaravelApproval\Notifications\ApprovalAssignedNotification;
use ByRcsc\LaravelApproval\Notifications\ApprovalOverdueNotification;
use ByRcsc\LaravelApproval\Notifications\ApprovalRejectedNotification;
use ByRcsc\LaravelApproval\Notifications\ApprovalRequestedNotification;
use ByRcsc\LaravelApproval\Notifications\ApprovalReturnedNotification;
use ByRcsc\LaravelApproval\Support\NotificationMap;
use ByRcsc\LaravelApproval\Tests\Stubs\HeadOfDepartmentResolver;
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

    it('asks the approvers of the stage now waiting', function (): void {
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

    it('asks every assignment on a stage with a required approvals', function (): void {
        $one = user('One');
        $two = user('Two');

        $workflow = workflow([['required_approvals' => 2, 'approvers' => [$one, $two]]]);
        post()->submitForApproval($workflow, null, user('Author'));

        Notification::assertSentTo($one, ApprovalRequestedNotification::class);
        Notification::assertSentTo($two, ApprovalRequestedNotification::class);
    });

    it('asks the delegate rather than the approver who handed the assignment over', function (): void {
        $first = user('First');
        $approver = user('Approver');
        $delegate = user('Delegate');

        $workflow = workflow([
            ['approvers' => [$first]],
            ['approvers' => [$approver]],
        ]);

        $request = post()->submitForApproval($workflow, null, user('Author'));

        // Delegate the queued stage, then let it become active.
        $assignment = $request->stages()->get()[1]->assignments()->sole();
        $assignment->forceFill([
            'delegated_to_type' => $delegate->getMorphClass(),
            'delegated_to_id' => $delegate->getKey(),
        ])->save();

        Notification::fake();
        Approval::approve($request, $first);

        Notification::assertSentTo($delegate, ApprovalRequestedNotification::class);
        Notification::assertNotSentTo($approver, ApprovalRequestedNotification::class);
    });

    it('tells the requester when it is approved', function (): void {
        $author = user('Author');
        $approver = user('Approver');

        $workflow = workflow([['approvers' => [$approver]]]);
        $request = post()->submitForApproval($workflow, null, $author);

        Approval::approve($request, $approver);

        Notification::assertSentTo($author, ApprovalApprovedNotification::class);
        Notification::assertNotSentTo($approver, ApprovalApprovedNotification::class);
    });

    it('tells the requester when it is rejected, with the reason', function (): void {
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

    it('tells the late approvers when a stage goes overdue', function (): void {
        Date::setTestNow('2026-07-28 09:00:00');

        $request = overdueRequest();
        $approver = $request->stages()->sole()->assignments()->sole()->approver;

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
            // A second stage with no approvers makes the whole submit fail
            // after the first stage's events would otherwise have fired.
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

    it('maps the events it ships with', function (): void {
        expect(array_keys(NotificationMap::defaults()))->toBe([
            ApprovalSubmitted::class,
            StageCompleted::class,
            ApprovalApproved::class,
            DraftConflicted::class,
            ApprovalRejected::class,
            ApprovalReturned::class,
            ApprovalDelegated::class,
            ApproverReassigned::class,
            ApproversResynced::class,
            ApproversEscalated::class,
            StageOverdue::class,
            StageOverdueReminder::class,
        ]);
    });
});

describe('assignment changes', function (): void {
    beforeEach(function (): void {
        withNotifications();
        Notification::fake();
    });

    it('tells a delegate the assignment is now theirs', function (): void {
        $approver = user('Approver');
        $delegate = user('Delegate');
        $request = post()->submitForApproval(workflow([['approvers' => [$approver]]]), null, user('Author'));

        Notification::fake();
        Approval::delegate($request, $approver, $delegate);

        Notification::assertSentTo($delegate, ApprovalAssignedNotification::class);
        Notification::assertNotSentTo($approver, ApprovalAssignedNotification::class);
    });

    it('tells a reassigned replacement, never the replaced holder', function (): void {
        $leaver = user('Leaver');
        $successor = user('Successor');
        $request = post()->submitForApproval(workflow([['approvers' => [$leaver]]]), null, user('Author'));

        Notification::fake();
        Approval::reassign($request, $request->stages()->sole()->assignments()->sole(), $successor, user('Admin'));

        Notification::assertSentTo($successor, ApprovalAssignedNotification::class);
        Notification::assertNotSentTo($leaver, ApprovalAssignedNotification::class);
    });

    it('tells the approvers a resync adds', function (): void {
        $head = user('Head');
        $deputy = user('Deputy');
        HeadOfDepartmentResolver::$heads = [$head];

        $workflow = workflow([['required_approvals' => 1, 'approvers' => [['resolver' => HeadOfDepartmentResolver::class]]]]);
        $request = post()->submitForApproval($workflow, null, user('Author'));

        HeadOfDepartmentResolver::$heads = [$head, $deputy];

        Notification::fake();
        Approval::resync($request, user('Operator'));

        Notification::assertSentTo($deputy, ApprovalAssignedNotification::class);
        Notification::assertNotSentTo($head, ApprovalAssignedNotification::class);
    });

    it('tells the approvers an escalation brings in', function (): void {
        Date::setTestNow('2026-07-28 09:00:00');
        $duty = user('Duty officer');
        HeadOfDepartmentResolver::$heads = [$duty];

        $request = overdueRequest(EscalationAction::Escalate, ['resolver' => HeadOfDepartmentResolver::class]);
        $original = $request->stages()->sole()->assignments()->sole()->approver;

        Date::setTestNow('2026-07-28 10:01:00');
        Notification::fake();
        Approval::escalateOverdue();

        Notification::assertSentTo($duty, ApprovalAssignedNotification::class);
        Notification::assertSentTo($original, ApprovalOverdueNotification::class);
        Notification::assertNotSentTo($duty, ApprovalOverdueNotification::class);

        Date::setTestNow();
    });

    it('tells the requester a return needs their revision', function (): void {
        $author = user('Author');
        $approver = user('Approver');
        $request = post()->submitForApproval(workflow([['approvers' => [$approver]]]), null, $author);

        Notification::fake();
        Approval::returnForRevision($request, $approver, 'Fix the title.');

        Notification::assertSentTo($author, ApprovalReturnedNotification::class);
        Notification::assertNotSentTo($approver, ApprovalReturnedNotification::class);
    });

    it('sends the overdue notification again on each reminder', function (): void {
        Date::setTestNow('2026-07-28 09:00:00');

        $request = post()->submitForApproval(workflow([[
            'sla_minutes' => 60,
            'remind_every_minutes' => 30,
            'approvers' => [user('Approver')],
        ]]), null, user('Author'));
        $approver = $request->stages()->sole()->assignments()->sole()->approver;

        Date::setTestNow('2026-07-28 10:01:00');
        Approval::escalateOverdue();

        Date::setTestNow('2026-07-28 10:31:30');
        Approval::escalateOverdue();

        Notification::assertSentToTimes($approver, ApprovalOverdueNotification::class, 2);

        Date::setTestNow();
    });
});
