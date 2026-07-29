<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Tests\Stubs;

use ByRcsc\LaravelApproval\Events\ApprovalEvent;
use ByRcsc\LaravelApproval\Notifications\ApprovalNotification;
use Illuminate\Database\Eloquent\Model;

/**
 * What a host maps `ApprovalResumed` to: the resumed request starts at level
 * one, so the people now waiting on it are that level's approvers.
 */
final class ResumedNoticeNotification extends ApprovalNotification
{
    /**
     * @return iterable<int, Model>
     */
    public static function recipients(ApprovalEvent $event): iterable
    {
        return self::activeApprovers($event);
    }
}
