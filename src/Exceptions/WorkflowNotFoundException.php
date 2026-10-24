<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Exceptions;

use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use Illuminate\Database\Eloquent\Model;

/**
 * Nothing to run. Distinct from {@see InvalidWorkflowException}, which means a
 * workflow was found and cannot be used.
 */
final class WorkflowNotFoundException extends ApprovalException
{
    public static function forSlug(string $slug): self
    {
        return new self("No approval workflow is registered under the slug [{$slug}].");
    }

    /**
     * The request has no stored workflow: it was deleted, or the request ran
     * under an inline definition. A retry has nothing to run against, so name
     * one explicitly to revise and resubmit anyway.
     */
    public static function forRequest(ApprovalRequest $request): self
    {
        $ulid = $request->getAttributeValue('ulid');

        return new self(sprintf(
            'Approval request %s has no stored workflow: it was deleted, or the request ran under an inline definition. Pass a workflow to resubmit it.',
            is_scalar($ulid) ? (string) $ulid : 'unsaved',
        ));
    }

    public static function forApprovable(Model $approvable): self
    {
        return new self(sprintf(
            'No approval workflow could be resolved for [%s]. Pass one to submitForApproval(), return one from approvalWorkflow(), map the model in the [approval.workflows] config, or register a workflow against the model type.',
            $approvable::class,
        ));
    }

    /**
     * Workflows were found for the model and every one of them was ruled out,
     * which is a different problem from finding none at all.
     *
     * @param  list<string>  $considered
     */
    public static function noneApplicable(Model $approvable, array $considered): self
    {
        return new self(sprintf(
            'No approval workflow applies to [%s]. Considered and ruled out as inactive or not applicable: [%s].',
            $approvable::class,
            implode('], [', $considered),
        ));
    }
}
