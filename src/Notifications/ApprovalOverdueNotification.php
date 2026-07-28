<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Notifications;

use ByRcsc\LaravelApproval\Events\ApprovalEvent;
use ByRcsc\LaravelApproval\Events\StepOverdue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * "This has been sitting with you past its deadline."
 *
 * Goes to whoever the late level is waiting on - the point of the default
 * `notify` policy, which changes nothing about the request and relies on this
 * to do the actual telling.
 *
 * Sent once at escalation by default. A level with `remind_every_minutes`
 * receives the same notification again at that snapshotted cadence.
 */
final class ApprovalOverdueNotification extends ApprovalNotification
{
    /**
     * @return iterable<int, Model>
     */
    public static function recipients(ApprovalEvent $event): iterable
    {
        return $event instanceof StepOverdue
            ? self::approversOf($event->step)
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
