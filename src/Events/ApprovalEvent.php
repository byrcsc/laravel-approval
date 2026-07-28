<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Events;

use ByRcsc\LaravelApproval\Models\ApprovalRequest;

/**
 * Every event this package fires carries the request it happened to, so a
 * listener that only cares which record moved can type-hint the base class and
 * be done.
 *
 * Events are fired inside the transaction that caused them. A listener that
 * does anything slow or external should be queued, and a queued listener should
 * dispatch after commit - the request it was handed may still be rolled back.
 */
abstract class ApprovalEvent
{
    public function __construct(public readonly ApprovalRequest $request) {}
}
