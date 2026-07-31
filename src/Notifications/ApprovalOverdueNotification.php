<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Notifications;

use ByRcsc\LaravelApproval\Events\ApprovalEvent;
use ByRcsc\LaravelApproval\Events\StageOverdue;
use ByRcsc\LaravelApproval\Events\StageOverdueReminder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Tells the approvers a stage is waiting on that its deadline has passed.
 *
 * The record-only overdue policy records the overdue state and emits an event;
 * delivery is this notification's job, and only when notifications are enabled.
 *
 * Sent once when the stage first goes overdue, and again for each
 * `StageOverdueReminder` at the stage's snapshotted cadence.
 */
final class ApprovalOverdueNotification extends ApprovalNotification
{
    /**
     * @return iterable<int, Model>
     */
    public static function recipients(ApprovalEvent $event): iterable
    {
        return $event instanceof StageOverdue || $event instanceof StageOverdueReminder
            ? self::approversOf($event->stage)
            : self::activeApprovers($event);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->mailFor('overdue');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'request' => $this->requestUlid(),
            'state' => 'overdue',
            'subject' => $this->subject(),
        ];
    }
}
