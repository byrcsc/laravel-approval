<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Enums;

/**
 * What a stage does when its deadline passes without a decision. Configured per
 * stage; each case maps to a strategy class through config, which is the seam a
 * host app overrides.
 */
enum EscalationAction: string
{
    case RecordOnly = 'record_only';
    case Escalate = 'escalate';
    case AutoApprove = 'auto_approve';
    case AutoReject = 'auto_reject';
}
