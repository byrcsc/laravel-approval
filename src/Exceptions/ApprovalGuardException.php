<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Exceptions;

use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use Illuminate\Database\Eloquent\Model;

final class ApprovalGuardException extends ApprovalException
{
    /**
     * @param  list<string>  $attributes
     */
    public static function draftedAttributes(Model $model, ApprovalRequest $request, array $attributes): self
    {
        $ulid = $request->getAttributeValue('ulid');

        return new self(sprintf(
            'Cannot save [%s] while approval request %s is pending. Drafted attributes are guarded: %s.',
            $model::class,
            is_scalar($ulid) ? (string) $ulid : 'unsaved',
            implode(', ', $attributes),
        ));
    }
}
