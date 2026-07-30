<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Notifications;

use ByRcsc\LaravelApproval\Events\ApprovalEvent;
use ByRcsc\LaravelApproval\Events\ApprovalRejected;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Tells the requester that their request was rejected, and why.
 *
 * A rejection closes the request, so the reason is included: the requester has
 * to decide whether to revise and resubmit.
 */
final class ApprovalRejectedNotification extends ApprovalNotification
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
            ->subject('Rejected: '.$this->subject())
            ->line($this->subject().' was not approved.');

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
            'state' => 'rejected',
            'subject' => $this->subject(),
            'reason' => $this->reason(),
        ];
    }

    private function reason(): ?string
    {
        return $this->event instanceof ApprovalRejected ? $this->event->comment : null;
    }
}
