<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Tests\Stubs;

use ByRcsc\LaravelApproval\Events\ApprovalEvent;
use ByRcsc\LaravelApproval\Notifications\ApprovalNotification;
use Illuminate\Database\Eloquent\Model;

/**
 * What a host maps `ApprovalResubmitted` to: the replacement request starts at
 * the first stage, so the people now waiting on it are that stage's approvers.
 */
final class ResubmittedNoticeNotification extends ApprovalNotification
{
    /**
     * @return iterable<int, Model>
     */
    public static function recipients(ApprovalEvent $event): iterable
    {
        return self::activeApprovers($event);
    }
}
