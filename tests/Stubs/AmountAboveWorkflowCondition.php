<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Tests\Stubs;

use ByRcsc\LaravelApproval\Contracts\WorkflowCondition;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflow;
use Illuminate\Database\Eloquent\Model;

/**
 * The everyday shape of a workflow condition: the same record goes down a
 * different workflow once it is worth enough.
 */
final class AmountAboveWorkflowCondition implements WorkflowCondition
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function applies(ApprovalWorkflow $workflow, Model $approvable, ?Model $requester, array $config): bool
    {
        $amount = $approvable->getAttributeValue('amount');
        $threshold = $config['amount'] ?? 0;

        return is_numeric($amount) && is_numeric($threshold) && (float) $amount > (float) $threshold;
    }
}
