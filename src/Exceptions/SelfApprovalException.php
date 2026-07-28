<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Exceptions;

use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use Illuminate\Database\Eloquent\Model;

/**
 * An approval chain somebody can walk themselves is not a control. Off by
 * default, and a workflow that genuinely wants it says so in its `settings`.
 */
final class SelfApprovalException extends ApprovalException
{
    public static function forSubmitter(Model $actor, ApprovalRequest $request): self
    {
        $ulid = $request->getAttributeValue('ulid');

        return new self(sprintf(
            '[%s] submitted approval request %s and may not approve it. Set [approval.allow_self_approval], or the workflow\'s settings.allow_self_approval, to permit this.',
            $actor::class,
            is_scalar($ulid) ? (string) $ulid : 'unsaved',
        ));
    }
}
