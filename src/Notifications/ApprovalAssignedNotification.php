<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Notifications;

use ByRcsc\LaravelApproval\Events\ApprovalDelegated;
use ByRcsc\LaravelApproval\Events\ApprovalEvent;
use ByRcsc\LaravelApproval\Events\ApproverReassigned;
use ByRcsc\LaravelApproval\Events\ApproversEscalated;
use ByRcsc\LaravelApproval\Events\ApproversResynced;
use ByRcsc\LaravelApproval\Models\ApprovalRequestAssignment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * "An approval assignment is now yours."
 *
 * Sent to whoever an assignment change makes responsible: the delegate of a
 * delegation, the replacement of a reassignment, and the approvers added by
 * resynchronization or escalation. Recipients are read from the event after
 * the assignment exists, so the replaced holder is never addressed.
 */
final class ApprovalAssignedNotification extends ApprovalNotification
{
    /**
     * @return iterable<int, Model>
     */
    public static function recipients(ApprovalEvent $event): iterable
    {
        return match (true) {
            $event instanceof ApprovalDelegated => [$event->delegate],
            $event instanceof ApproverReassigned => [$event->to],
            $event instanceof ApproversEscalated => self::holdersOf($event->assignments),
            $event instanceof ApproversResynced => self::holdersOf($event->addedAssignments),
            default => [],
        };
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Approval assigned to you: '.$this->subject())
            ->line('An approval assignment for '.$this->subject().' is now yours.')
            ->line('Reference: '.$this->requestUlid().'.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'request' => $this->requestUlid(),
            'state' => 'assigned',
            'subject' => $this->subject(),
        ];
    }

    /**
     * Freshly created assignments are never delegated, so the holder is the
     * named approver.
     *
     * @param  list<ApprovalRequestAssignment>  $assignments
     * @return list<Model>
     */
    private static function holdersOf(array $assignments): array
    {
        $holders = [];

        foreach ($assignments as $assignment) {
            $holder = $assignment->approver;

            if ($holder instanceof Model) {
                $holders[] = $holder;
            }
        }

        return $holders;
    }
}
