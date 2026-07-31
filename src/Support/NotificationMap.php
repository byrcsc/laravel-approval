<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Support;

use ByRcsc\LaravelApproval\Events\ApprovalApproved;
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
use ByRcsc\LaravelApproval\Exceptions\InvalidConfigurationException;
use ByRcsc\LaravelApproval\Notifications\ApprovalApprovedNotification;
use ByRcsc\LaravelApproval\Notifications\ApprovalAssignedNotification;
use ByRcsc\LaravelApproval\Notifications\ApprovalConflictNotification;
use ByRcsc\LaravelApproval\Notifications\ApprovalNotification;
use ByRcsc\LaravelApproval\Notifications\ApprovalOverdueNotification;
use ByRcsc\LaravelApproval\Notifications\ApprovalRejectedNotification;
use ByRcsc\LaravelApproval\Notifications\ApprovalRequestedNotification;
use ByRcsc\LaravelApproval\Notifications\ApprovalReturnedNotification;

/**
 * Which event sends which notification.
 *
 * Notification delivery is opt-in: it is disabled by default so that installing
 * the package cannot send external messages, and so that an application's own
 * notification rules stay in charge.
 *
 * Enabling it activates the entries below. Setting an entry to null silences
 * that event; pointing it at a class extending {@see ApprovalNotification}
 * replaces both the message and its recipients.
 *
 * Not every event is mapped. The mapped ones are the state changes that leave
 * someone waiting on an action; the rest are available to listen to.
 */
final class NotificationMap
{
    /**
     * @var array<class-string, class-string<ApprovalNotification>>
     */
    private const DEFAULTS = [
        ApprovalSubmitted::class => ApprovalRequestedNotification::class,
        StageCompleted::class => ApprovalRequestedNotification::class,
        ApprovalApproved::class => ApprovalApprovedNotification::class,
        DraftConflicted::class => ApprovalConflictNotification::class,
        ApprovalRejected::class => ApprovalRejectedNotification::class,
        ApprovalReturned::class => ApprovalReturnedNotification::class,
        ApprovalDelegated::class => ApprovalAssignedNotification::class,
        ApproverReassigned::class => ApprovalAssignedNotification::class,
        ApproversResynced::class => ApprovalAssignedNotification::class,
        ApproversEscalated::class => ApprovalAssignedNotification::class,
        StageOverdue::class => ApprovalOverdueNotification::class,
        StageOverdueReminder::class => ApprovalOverdueNotification::class,
    ];

    /**
     * @return array<class-string, class-string<ApprovalNotification>>
     */
    public static function defaults(): array
    {
        return self::DEFAULTS;
    }

    public static function enabled(): bool
    {
        return (bool) config('approval.notifications.enabled', false);
    }

    /**
     * The map as it actually stands: empty when notifications are off, and
     * with null entries dropped rather than kept around as holes.
     *
     * @return array<class-string, class-string<ApprovalNotification>>
     */
    public static function resolved(): array
    {
        if (! self::enabled()) {
            return [];
        }

        /** @var mixed $configured */
        $configured = config('approval.notifications.map');

        if (! is_array($configured)) {
            return self::DEFAULTS;
        }

        $map = [];

        foreach ($configured as $event => $notification) {
            if (! is_string($event) || ! is_string($notification)) {
                continue;
            }

            if (class_exists($event) && is_subclass_of($notification, ApprovalNotification::class)) {
                $map[$event] = $notification;
            }
        }

        return $map;
    }

    /**
     * Checked at boot rather than when the first request is submitted: a typo
     * here is silent until the day somebody was supposed to be told something.
     *
     * A null entry is not an error - it is how an event is switched off.
     *
     * @param  array<string, mixed>  $notifications
     */
    public static function validate(array $notifications): void
    {
        $map = Payload::object($notifications, 'map');

        if ($map === null) {
            return;
        }

        foreach ($map as $event => $notification) {
            if ($notification === null) {
                continue;
            }

            if (! class_exists($event)) {
                throw InvalidConfigurationException::unknownNotificationEvent($event);
            }

            if (! is_string($notification) || ! class_exists($notification)) {
                throw InvalidConfigurationException::unknownNotification($event);
            }

            if (! is_subclass_of($notification, ApprovalNotification::class)) {
                throw InvalidConfigurationException::notificationIsNotANotification($event, $notification);
            }
        }
    }
}
