<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Contracts;

use ByRcsc\LaravelApproval\Models\ApprovalWorkflow;
use Illuminate\Database\Eloquent\Model;

/**
 * Decides whether a workflow applies to one submission, which is what lets a
 * model have more than one workflow registered against it.
 *
 * The stage-level {@see StageCondition} answers a different question: given
 * that this workflow is running, does this stage apply. A workflow condition
 * runs first, and only while a workflow is being chosen - it is never consulted
 * for a workflow the caller named outright.
 */
interface WorkflowCondition
{
    /**
     * `$approvable` carries the draft values when the submission holds one, so
     * the condition judges the proposal rather than the record behind it. The
     * requester is null when nobody is submitting yet, which is the case when
     * an application asks which workflows a record could be submitted against.
     *
     * @param  array<string, mixed>  $config
     */
    public function applies(ApprovalWorkflow $workflow, Model $approvable, ?Model $requester, array $config): bool;
}
