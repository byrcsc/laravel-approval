<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Exceptions;

use ByRcsc\LaravelApproval\Models\ApprovalAction;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;

/**
 * An attachment that cannot mean what its caller said it means.
 */
final class AttachmentException extends ApprovalException
{
    public static function blankLocation(): self
    {
        return new self('An attachment needs both a disk and a path; the bytes live with the host, and a reference to nowhere is not evidence.');
    }

    public static function invalidChecksum(): self
    {
        return new self('An attachment needs a SHA-256 checksum so the referenced bytes, not only their location, are signed into the audit trail.');
    }

    public static function invalidSize(): self
    {
        return new self('An attachment size cannot be negative.');
    }

    public static function actionNotOnRequest(ApprovalRequest $request, ApprovalAction $action): self
    {
        $ulid = $request->getAttributeValue('ulid');
        $key = $action->getKey();

        return new self(sprintf(
            'Action %s does not belong to approval request %s, so an attachment cannot support it there.',
            is_scalar($key) ? (string) $key : 'unsaved',
            is_scalar($ulid) ? (string) $ulid : 'unsaved',
        ));
    }
}
