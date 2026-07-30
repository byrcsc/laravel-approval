<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Notifications;

use ByRcsc\LaravelApproval\Events\ApprovalEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * "Your request cleared every stage."
 *
 * Goes to the submitter only after the request is approved and any draft was
 * applied successfully.
 */
final class ApprovalApprovedNotification extends ApprovalNotification
{
    /**
     * @return iterable<int, Model>
     */
    public static function recipients(ApprovalEvent $event): iterable
    {
        return self::submitterOf($event);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Approved: '.$this->subject())
            ->line($this->subject().' has been approved.')
            ->line('Reference: '.$this->requestUlid().'.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'request' => $this->requestUlid(),
            'state' => 'approved',
            'subject' => $this->subject(),
        ];
    }
}
