<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Notifications;

use ByRcsc\LaravelApproval\Events\ApprovalEvent;
use ByRcsc\LaravelApproval\Events\ApprovalReturned;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * "Your request was returned for revision, and here is what to fix."
 *
 * Goes to the requester: a returned request waits on them to revise and
 * resubmit it, and nothing moves until they do.
 */
final class ApprovalReturnedNotification extends ApprovalNotification
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
        $message = (new MailMessage)
            ->subject('Returned for revision: '.$this->subject())
            ->line($this->subject().' was returned to you for revision.');

        if ($this->reason() !== null) {
            $message->line('Reason: '.$this->reason());
        }

        return $message->line('Reference: '.$this->requestUlid().'.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'request' => $this->requestUlid(),
            'state' => 'returned',
            'subject' => $this->subject(),
            'reason' => $this->reason(),
        ];
    }

    private function reason(): ?string
    {
        return $this->event instanceof ApprovalReturned ? $this->event->comment : null;
    }
}
