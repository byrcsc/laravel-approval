<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Listeners;

use ByRcsc\LaravelApproval\Events\ApprovalEvent;
use ByRcsc\LaravelApproval\Notifications\ApprovalNotification;
use ByRcsc\LaravelApproval\Support\NotificationMap;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Turns a mapped event into a sent notification.
 *
 * Registered only for the events the config actually maps, and only when
 * notifications are enabled - so an application that leaves them off pays for
 * none of this.
 *
 * The send is deferred to after the transaction commits. Every event in this
 * package fires inside one, and a request that is rolled back a line later
 * should not have already told six people it needs their approval. Outside a
 * transaction the callback runs immediately, so nothing is delayed that does
 * not need to be.
 */
final class SendApprovalNotification
{
    public function __invoke(ApprovalEvent $event): void
    {
        $notification = NotificationMap::resolved()[$event::class] ?? null;

        if ($notification === null) {
            return;
        }

        $recipients = [...$notification::recipients($event)];

        if ($recipients === []) {
            return;
        }

        $instance = new $notification($event);

        DB::afterCommit(static function () use ($recipients, $instance): void {
            Notification::send($recipients, $instance);
        });
    }

    /**
     * Kept explicit so the container can build one without reflection over an
     * invokable, and so tests can name the method.
     */
    public function handle(ApprovalEvent $event): void
    {
        $this($event);
    }

    /**
     * @return class-string<ApprovalNotification>|null
     */
    public static function notificationFor(ApprovalEvent $event): ?string
    {
        return NotificationMap::resolved()[$event::class] ?? null;
    }
}
