<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Notifications;

use ByRcsc\LaravelApproval\Events\ApprovalEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Tells an approver that a request is waiting on their decision.
 *
 * Mapped to both `ApprovalSubmitted` and `StageCompleted`: an initial assignment
 * and a next-stage assignment are the same fact for the approver receiving it.
 * In both cases the recipients are the approvers the now-active stage is waiting
 * on.
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
