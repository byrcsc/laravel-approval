<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Data;

use ByRcsc\LaravelApproval\Enums\Verdict;
use ByRcsc\LaravelApproval\Exceptions\CommentRequiredException;
use Illuminate\Database\Eloquent\Model;

/**
 * One person's answer, as a value.
 *
 * The rejection-needs-a-reason rule is enforced here rather than in the action,
 * so a decision that reaches the engine is already coherent - and so a
 * controller can find out it is missing a comment while it still has a form to
 * put the error back on.
 */
final readonly class ApprovalDecision
{
    /**
     * @param  array<string, mixed>  $metadata  recorded on the audit action as-is
     */
    public function __construct(
        public Model $actor,
        public Verdict $verdict,
        public ?string $comment = null,
        public array $metadata = [],
    ) {
        if ($verdict === Verdict::Reject && trim($comment ?? '') === '') {
            throw CommentRequiredException::toReject();
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function approve(Model $actor, ?string $comment = null, array $metadata = []): self
    {
        return new self($actor, Verdict::Approve, $comment, $metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function reject(Model $actor, string $comment, array $metadata = []): self
    {
        return new self($actor, Verdict::Reject, $comment, $metadata);
    }

    /**
     * Blank and absent are the same thing to everything downstream, so they are
     * collapsed here rather than at each reader.
     */
    public function comment(): ?string
    {
        $comment = trim($this->comment ?? '');

        return $comment === '' ? null : $comment;
    }
}
