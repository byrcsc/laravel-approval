<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Enums;

/**
 * What an approver decided. Narrower than {@see ActionType}, which also covers
 * everything that happens to a request without anybody deciding anything.
 *
 * Exists so a controller can take one form submission and hand the engine a
 * whole decision, rather than branching on the verdict itself.
 */
enum Verdict: string
{
    case Approve = 'approve';
    case Reject = 'reject';
    case Return = 'return';
}
