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
     * The workflow a request ran under has been deleted, so a retry has nothing
     * to run against. Name one explicitly to revise and resubmit anyway.
     */
    public static function forRequest(ApprovalRequest $request): self
    {
        $ulid = $request->getAttributeValue('ulid');

        return new self(sprintf(
            'The workflow approval request %s ran under no longer exists. Pass a workflow to resubmit it under a different one.',
            is_scalar($ulid) ? (string) $ulid : 'unsaved',
        ));
    }

    public static function forApprovable(Model $approvable): self
    {
        return new self(sprintf(
            'No approval workflow could be resolved for [%s]. Pass one to submitForApproval(), return one from approvalWorkflow(), or map the model in the [approval.workflows] config.',
            $approvable::class,
        ));
    }
}
