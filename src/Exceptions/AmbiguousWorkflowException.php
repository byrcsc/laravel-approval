<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Exceptions;

use Illuminate\Database\Eloquent\Model;

/**
 * Two workflows apply to one submission and nothing separates them, so the
 * package refuses to pick. Choosing arbitrarily would send a request down a
 * path nobody configured, and the next submission could take the other one.
 */
final class AmbiguousWorkflowException extends ApprovalException
{
    /**
     * @param  list<string>  $slugs
     */
    public static function forApprovable(Model $approvable, array $slugs, int $priority): self
    {
        return new self(sprintf(
            'More than one approval workflow applies to [%s] at priority %d: [%s]. Give one of them a higher priority, narrow them with a condition, or name the workflow at the call site.',
            $approvable::class,
            $priority,
            implode('], [', $slugs),
        ));
    }
}
