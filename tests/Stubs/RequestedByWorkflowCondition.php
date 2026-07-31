<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Tests\Stubs;

use ByRcsc\LaravelApproval\Contracts\WorkflowCondition;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflow;
use Illuminate\Database\Eloquent\Model;

/**
 * A condition about who is submitting, which is also how the null requester -
 * nobody is submitting yet - stays visible to the condition.
 */
final class RequestedByWorkflowCondition implements WorkflowCondition
{
    public static ?bool $sawRequester = null;

    /**
     * @param  array<string, mixed>  $config
     */
    public function applies(ApprovalWorkflow $workflow, Model $approvable, ?Model $requester, array $config): bool
    {
        self::$sawRequester = $requester !== null;

        return $requester?->getAttributeValue('name') === ($config['name'] ?? null);
    }
}
