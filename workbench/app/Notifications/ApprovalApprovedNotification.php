<?php

declare(strict_types=1);

namespace Workbench\App\Notifications;

use ByRcsc\LaravelApproval\Events\ApprovalEvent;
use ByRcsc\LaravelApproval\Notifications\ApprovalNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Workbench\App\Models\Team;

/**
 * The demo's replacement for the shipped approved notification, registered
 * against `ApprovalApproved` in `WorkbenchServiceProvider`'s notification map.
 *
 * A notification declares its own recipients in this package, which is unusual
 * and deliberate: the audience is the part applications most often want to
 * change, and holding it here means one map entry replaces the message and the
 * audience together, with no listener to override.
 *
 * The shipped version tells the requester. This one also tells the finance
 * team, because in the demo they are the ones who pay the invoice - a rule about
 * this organization, which is exactly the kind the package will not guess.
 *
 * Replacements extend `ApprovalNotification`, the one notification class that is
 * not final. `via()` and `mailFor()` come from it, so this still honours
 * `approval.notifications.channels` and still renders the publishable
 * `approval::mail.approved` view with the publishable wording.
 */
final class ApprovalApprovedNotification extends ApprovalNotification
{
    /**
     * `Notification::send()` deduplicates nothing, so a requester who is also on
     * the finance team is filtered out of the team list rather than being sent
     * the same message twice.
     *
     * @return iterable<int, Model>
     */
    public static function recipients(ApprovalEvent $event): iterable
    {
        $requester = $event->request->requester;
        $recipients = $requester instanceof Model ? [$requester] : [];

        $finance = Team::query()->where('slug', 'finance')->first();

        if (! $finance instanceof Team) {
            return $recipients;
        }

        foreach ($finance->members as $member) {
            if (! $requester instanceof Model || ! $member->is($requester)) {
                $recipients[] = $member;
            }
        }

        return $recipients;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->mailFor('approved')
            ->line('The finance team received a copy of this for payment.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'request' => $this->request()->getAttributeValue('ulid'),
            'state' => 'approved',
            'subject' => $this->subject(),
        ];
    }
}
