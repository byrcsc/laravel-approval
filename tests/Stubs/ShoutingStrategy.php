<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Tests\Stubs;

use ByRcsc\LaravelApproval\Contracts\EscalationStrategy;
use ByRcsc\LaravelApproval\Enums\EscalationAction;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStage;

/**
 * A host app's own escalation policy, standing in for whatever they would
 * actually do - page somebody, open a ticket, post to a channel.
 */
class ShoutingStrategy implements EscalationStrategy
{
    /**
     * @var list<mixed>
     */
    public static array $handled = [];

    /**
     * @param  array<string, mixed>  $config
     */
    public function handle(
        ApprovalRequest $request,
        ApprovalRequestStage $stage,
        EscalationAction $action,
        array $config,
    ): void {
        self::$handled[] = $request->getKey();
    }
}
