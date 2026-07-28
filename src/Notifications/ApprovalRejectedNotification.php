<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Notifications;

use ByRcsc\LaravelApproval\Events\ApprovalEvent;
use ByRcsc\LaravelApproval\Events\ApprovalRejected;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * "Your request was turned down, and here is why."
 *
 * The reason is the point - a rejection ends the request and the submitter has
 * to decide whether to resubmit, which they cannot do usefully without it.
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
