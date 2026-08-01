<?php

declare(strict_types=1);

namespace Workbench\App\Approval\Conditions;

use ByRcsc\LaravelApproval\Contracts\WorkflowCondition;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflow;
use Illuminate\Database\Eloquent\Model;

/**
 * Chooses the executive purchase-order workflow for anything at or above a
 * threshold.
 *
 * Both purchase-order workflows are registered against the same model type, so
 * this is the rule that picks between them. `priority` on the workflow row
 * orders whatever survives, which is why the executive workflow carries a higher
 * one: when both apply, the more specific must win outright rather than by
 * insertion order.
 *
 * `$approvable` carries the draft when the submission holds one, so this judges
 * the amount being proposed rather than the amount currently on the record.
 * That is the whole point on a purchase order: raising one from 900 to 90,000
 * has to reach the executive workflow.
 *
 * `$requester` is null when nobody is submitting yet - `Approval::workflowsFor()`
 * asks which workflows a record could go through before there is a requester.
 * A condition that needs one should return false rather than assume.
 */
final class AmountAtOrAboveWorkflow implements WorkflowCondition
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function applies(ApprovalWorkflow $workflow, Model $approvable, ?Model $requester, array $config): bool
    {
        $threshold = $config['amount'] ?? null;
        $amount = $approvable->getAttributeValue('amount');

        return is_int($threshold)
            && is_numeric($amount)
            && (int) $amount >= $threshold;
    }
}
