<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Exceptions;

use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use Illuminate\Database\Eloquent\Model;

/**
 * Raised when a requester tries to approve their own request. Self-approval is
 * disallowed by default; a workflow that permits it sets `allow_self_approval`
 * in its `settings`.
 */
final class SelfApprovalException extends ApprovalException
{
    public static function forRequester(Model $actor, ApprovalRequest $request): self
    {
        $ulid = $request->getAttributeValue('ulid');

        return new self(sprintf(
            '[%s] submitted approval request %s and may not approve it. Set [approval.allow_self_approval], or the workflow\'s settings.allow_self_approval, to permit this.',
            $actor::class,
            is_scalar($ulid) ? (string) $ulid : 'unsaved',
        ));
    }
}
