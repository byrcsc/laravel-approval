<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Support;

use ByRcsc\LaravelApproval\Events\ApprovalApproved;
use ByRcsc\LaravelApproval\Events\ApprovalRejected;
use ByRcsc\LaravelApproval\Events\ApprovalSubmitted;
use ByRcsc\LaravelApproval\Events\DraftConflicted;
use ByRcsc\LaravelApproval\Events\LevelCompleted;
use ByRcsc\LaravelApproval\Events\StepOverdue;
use ByRcsc\LaravelApproval\Exceptions\InvalidConfigurationException;
use ByRcsc\LaravelApproval\Notifications\ApprovalApprovedNotification;
use ByRcsc\LaravelApproval\Notifications\ApprovalConflictNotification;
use ByRcsc\LaravelApproval\Notifications\ApprovalNotification;
use ByRcsc\LaravelApproval\Notifications\ApprovalOverdueNotification;
use ByRcsc\LaravelApproval\Notifications\ApprovalRejectedNotification;
use ByRcsc\LaravelApproval\Notifications\ApprovalRequestedNotification;

/**
 * Which event sends which notification.
 *
 * Notifications are off entirely by default. A package that starts mailing
 * people the moment it is installed is a package that gets uninstalled, and
 * every application already has opinions about who hears what.
 *
 * Turning them on gets the five entries below. Setting any entry to null
 * silences that event; pointing it at your own class - anything extending
 * {@see ApprovalNotification} - changes both the message and its audience.
 *
 * Note that only five of the dozen events are mapped. The rest exist to be
 * listened to; not every state change is worth an email, and the ones chosen
 * here are the ones somebody is actually waiting on.
 */
final class NotificationMap
{
    /**
     * @var array<class-string, class-string<ApprovalNotification>>
     */
    private const DEFAULTS = [
        ApprovalSubmitted::class => ApprovalRequestedNotification::class,
        LevelCompleted::class => ApprovalRequestedNotification::class,
        ApprovalApproved::class => ApprovalApprovedNotification::class,
        DraftConflicted::class => ApprovalConflictNotification::class,
        ApprovalRejected::class => ApprovalRejectedNotification::class,
        StepOverdue::class => ApprovalOverdueNotification::class,
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
     * with null entries dropped rather than carried around as holes.
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
