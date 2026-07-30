<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Data;

/**
 * The draft a revised request starts with.
 *
 * Revising a request has three distinct draft choices, and null cannot carry
 * all of them: carry the previous request's draft forward unchanged, submit a
 * revised draft, or submit no draft at all and put the record itself under
 * approval. Each constructor names one choice, so the caller states which they
 * meant.
 */
final readonly class RevisedDraft
{
    /**
     * @param  array<string, mixed>|null  $attributes
     */
    private function __construct(
        private bool $inherits,
        private ?array $attributes,
    ) {}

    /**
     * Carry the previous request's draft forward unchanged. A previous request
     * with no draft produces a record-level replacement.
     */
    public static function inherit(): self
    {
        return new self(true, null);
    }

    /**
     * Submit no draft: the replacement puts the already-persisted record under
     * approval rather than an edit to it.
     */
    public static function none(): self
    {
        return new self(false, null);
    }

    /**
     * Submit a revised draft. The attributes replace the previous draft
     * entirely, so keys may be added, changed, or left out; every key is
     * validated against the model's allowed draft attributes on submission.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function withAttributes(array $attributes): self
    {
        return new self(false, $attributes);
    }

    /**
     * @param  array<string, mixed>|null  $previous  the draft of the request being revised
     * @return array<string, mixed>|null
     */
    public function resolve(?array $previous): ?array
    {
        return $this->inherits ? $previous : $this->attributes;
    }
}
