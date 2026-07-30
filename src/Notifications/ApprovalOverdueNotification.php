<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Notifications;

use ByRcsc\LaravelApproval\Events\ApprovalEvent;
use ByRcsc\LaravelApproval\Events\StageOverdue;
use ByRcsc\LaravelApproval\Events\StageOverdueReminder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * "This has been sitting with you past its deadline."
 *
 * Goes to whoever the late stage is waiting on - the delivery the record-only
 * policy deliberately leaves to the notification layer.
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
        return (new MailMessage)
            ->subject('Overdue: '.$this->subject())
            ->line('An approval waiting on you has passed its deadline: '.$this->subject().'.')
            ->line('Reference: '.$this->requestUlid().'.');
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
