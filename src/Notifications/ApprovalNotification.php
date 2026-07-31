<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Notifications;

use ByRcsc\LaravelApproval\Data\StageProgress;
use ByRcsc\LaravelApproval\Enums\StageStatus;
use ByRcsc\LaravelApproval\Events\ApprovalEvent;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStage;
use ByRcsc\LaravelApproval\Support\Attributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The base class every approval notification extends, including replacements
 * written by the host application.
 *
 * Unusually for Laravel, a notification declares its own recipients. Recipients
 * are the part applications most often change, so keeping them on the class
 * means one entry in the notification map replaces both the message and its
 * audience, without having to replace a listener.
 *
 * Nothing is sent unless `approval.notifications.enabled` is true. Each event's
 * entry in the map can be set to null to silence that event alone.
 */
abstract class ApprovalNotification extends Notification
{
    public function __construct(public readonly ApprovalEvent $event) {}

    /**
     * The recipients of this notification.
     *
     * An empty result is valid and sends nothing, which is what happens when a
     * stage's approver records have all been deleted.
     *
     * @return iterable<int, Model>
     */
    abstract public static function recipients(ApprovalEvent $event): iterable;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        /** @var mixed $channels */
        $channels = config('approval.notifications.channels', ['mail']);

        if (! is_array($channels)) {
            return ['mail'];
        }

        return array_values(array_filter($channels, is_string(...)));
    }

    public function request(): ApprovalRequest
    {
        return $this->event->request;
    }

    /**
     * The record under approval, or null when it has been deleted since.
     */
    public function approvable(): ?Model
    {
        $approvable = $this->event->request->approvable;

        return $approvable instanceof Model ? $approvable : null;
    }

    /**
     * A readable name for the record under approval, falling back to its class
     * and key so the subject always identifies something.
     */
    public function subject(): string
    {
        $approvable = $this->approvable();

        if ($approvable === null) {
            return self::line('approval::approval.deleted_record');
        }

        foreach (['name', 'title', 'reference', 'label'] as $attribute) {
            $value = $approvable->getAttributeValue($attribute);

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        $key = $approvable->getKey();

        return class_basename($approvable).' #'.(is_scalar($key) ? (string) $key : '?');
    }

    /**
     * @param  array<string, mixed>  $with
     */
    protected function mailFor(string $key, array $with = []): MailMessage
    {
        $subject = $this->subject();

        return (new MailMessage)
            ->subject(self::line("approval::approval.{$key}.subject", ['subject' => $subject]))
            ->markdown("approval::mail.{$key}", [
                'subject' => $subject,
                'reference' => $this->requestUlid(),
                'request' => $this->request(),
                'approvable' => $this->approvable(),
                'notification' => $this,
                ...$with,
            ]);
    }

    /**
     * @param  array<string, string>  $replace
     */
    protected static function line(string $key, array $replace = []): string
    {
        $line = trans($key, $replace);

        return is_string($line) ? $line : $key;
    }

    /**
     * Whoever the request's active stage is currently waiting on.
     *
     * @return list<Model>
     */
    protected static function activeApprovers(ApprovalEvent $event): array
    {
        /** @var ApprovalRequestStage|null $stage */
        $stage = $event->request->stages()->where('state', StageStatus::Active->value)->first();

        return $stage === null ? [] : self::approversOf($stage);
    }

    /**
     * The assignment holders for a stage: the delegate where an assignment was
     * delegated, and the assigned approver otherwise.
     *
     * @return list<Model>
     */
    protected static function approversOf(ApprovalRequestStage $stage): array
    {
        return StageProgress::for($stage)->assignmentHolders();
    }

    /**
     * @return list<Model>
     */
    protected static function requesterOf(ApprovalEvent $event): array
    {
        $requester = $event->request->requester;

        return $requester instanceof Model ? [$requester] : [];
    }

    protected function requestUlid(): string
    {
        return Attributes::string($this->event->request, 'ulid');
    }
}
