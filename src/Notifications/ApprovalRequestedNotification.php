<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Notifications;

use ByRcsc\LaravelApproval\Events\ApprovalEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * "Something needs your approval."
 *
 * Mapped to both `ApprovalSubmitted` and `LevelCompleted`, because being asked
 * first and being asked next are the same thing from the approver's side. In
 * either case the recipients are whoever the now-active level is waiting on.
 */
final class ApprovalRequestedNotification extends ApprovalNotification
{
    /**
     * @return iterable<int, Model>
     */
    public static function recipients(ApprovalEvent $event): iterable
    {
        return self::activeApprovers($event);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Approval needed: '.$this->subject())
            ->line('You have been asked to approve '.$this->subject().'.')
            ->line('Reference: '.$this->requestUlid().'.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'request' => $this->requestUlid(),
            'state' => 'pending',
            'subject' => $this->subject(),
        ];
    }
}
