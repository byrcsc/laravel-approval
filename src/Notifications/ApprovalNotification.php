<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Notifications;

use ByRcsc\LaravelApproval\Data\StageProgress;
use ByRcsc\LaravelApproval\Enums\StepState;
use ByRcsc\LaravelApproval\Events\ApprovalEvent;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStep;
use ByRcsc\LaravelApproval\Support\Attributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;

/**
 * The base every approval notification extends, including any you write.
 *
 * It carries one unusual responsibility: a notification says who it goes to.
 * That is not the Laravel convention, and the reason it is done here is that
 * the audience is the part hosts most often want to change - "tell the
 * requester's manager too", "only mail the finance approvers". Keeping the
 * recipients on the class means swapping one map entry changes both the message
 * and who reads it, rather than leaving the audience stranded in a listener the
 * host would have to fork.
 *
 * Nothing is sent unless `approval.notifications.enabled` is true, and each
 * event's entry in the map can be set to null to silence just that one.
 */
abstract class ApprovalNotification extends Notification
{
    public function __construct(public readonly ApprovalEvent $event) {}

    /**
     * Who should hear about this.
     *
     * Returning nothing is fine and means nobody is told - a level whose
     * approvers have all been deleted, say.
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
     * A human-readable name for the record. Falls back to the class and key,
     * because a subject line naming nothing is worse than an ugly one.
     */
    public function subject(): string
    {
        $approvable = $this->approvable();

        if ($approvable === null) {
            return 'a deleted record';
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
     * Whoever the request's active level is currently waiting on.
     *
     * @return list<Model>
     */
    protected static function activeApprovers(ApprovalEvent $event): array
    {
        /** @var ApprovalRequestStep|null $step */
        $step = $event->request->steps()->where('state', StepState::Active->value)->first();

        return $step === null ? [] : self::approversOf($step);
    }

    /**
     * The people who can act on a step: the delegate where a seat was handed
     * over, the original approver everywhere else.
     *
     * @return list<Model>
     */
    protected static function approversOf(ApprovalRequestStep $step): array
    {
        return StageProgress::for($step)->assignmentHolders();
    }

    /**
     * @return list<Model>
     */
    protected static function submitterOf(ApprovalEvent $event): array
    {
        $submitter = $event->request->submitter;

        return $submitter instanceof Model ? [$submitter] : [];
    }

    protected function requestUlid(): string
    {
        return Attributes::string($this->event->request, 'ulid');
    }
}
