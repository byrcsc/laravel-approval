<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Notifications;

use ByRcsc\LaravelApproval\Events\ApprovalEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Notifies the requester that an approved draft still needs conflict
 * resolution and has not been applied.
 */
final class ApprovalConflictNotification extends ApprovalNotification
{
    /**
     * @return iterable<int, Model>
     */
    public static function recipients(ApprovalEvent $event): iterable
    {
        return self::requesterOf($event);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->mailFor('conflicted');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'request' => $this->requestUlid(),
            'state' => 'conflicted',
            'subject' => $this->subject(),
        ];
    }
}
