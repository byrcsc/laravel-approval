<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Tests\Stubs;

use ByRcsc\LaravelApproval\Events\ApprovalEvent;
use ByRcsc\LaravelApproval\Notifications\ApprovalNotification;
use Illuminate\Database\Eloquent\Model;

/**
 * What a host maps `ApprovalReturned` to: the requester is the one with
 * something to fix, so the requester is who hears about it.
 */
final class ReturnedNoticeNotification extends ApprovalNotification
{
    /**
     * @return iterable<int, Model>
     */
    public static function recipients(ApprovalEvent $event): iterable
    {
        return self::requesterOf($event);
    }
}
