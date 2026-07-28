<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Exceptions;

use Illuminate\Database\Eloquent\Model;

/**
 * The proposed edit is not something this engine will carry. Loud rather than
 * filtered: a draft that silently drops half its keys gets approved on the
 * strength of changes that were never going to be applied.
 */
final class InvalidDraftException extends ApprovalException
{
    /**
     * @param  list<string>  $attributes
     * @param  list<string>  $allowed
     */
    public static function undraftableAttributes(Model $approvable, array $attributes, array $allowed): self
    {
        return new self(sprintf(
            '[%s] does not accept %s as %s. approvableAttributes() allows: %s.',
            $approvable::class,
            implode(', ', $attributes),
            count($attributes) === 1 ? 'a draftable attribute' : 'draftable attributes',
            $allowed === [] ? 'nothing' : implode(', ', $allowed),
        ));
    }

    public static function unsavedApprovable(Model $approvable): self
    {
        return new self(sprintf(
            '[%s] has not been saved. Persist the record first - the engine gates it, it does not hold it.',
            $approvable::class,
        ));
    }

    public static function missingApprovable(Model $approvable): self
    {
        return new self(sprintf(
            '[%s] no longer exists. The engine cannot create an approval request for a missing record.',
            $approvable::class,
        ));
    }

    public static function emptyDraft(Model $approvable): self
    {
        return new self(sprintf(
            'The draft submitted for [%s] is empty. Pass null to gate the record itself rather than an edit to it.',
            $approvable::class,
        ));
    }

    /**
     * @param  list<string>  $attributes
     * @param  list<string>  $allowed
     */
    public static function resumeAddsAttributes(array $attributes, array $allowed): self
    {
        return new self(sprintf(
            'A returned draft may only edit its original attributes. New attributes: %s. Original draft: %s.',
            implode(', ', $attributes),
            $allowed === [] ? 'none' : implode(', ', $allowed),
        ));
    }
}
