<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Data;

use ByRcsc\LaravelApproval\Enums\ApproverSource;
use ByRcsc\LaravelApproval\Exceptions\InvalidWorkflowException;
use Illuminate\Database\Eloquent\Model;

/**
 * One assignment, before it becomes a row.
 *
 * Resolvers hand back models and concrete assignments hand back a morph pair;
 * this is the shape both collapse to, which is where duplicates get noticed -
 * the same person reached twice by two resolvers is one assignment, not two, and
 * counting them twice would quietly halve the approvals a stage really has.
 */
final readonly class AssignmentSnapshot
{
    public function __construct(
        public string $type,
        public int|string $id,
        public ApproverSource $source,
    ) {}

    public static function forModel(Model $approver, ApproverSource $source, string $resolver = ''): self
    {
        $key = $approver->getKey();

        if (! is_int($key) && ! is_string($key)) {
            throw InvalidWorkflowException::resolverReturnedUnsavedModel($resolver === '' ? $approver::class : $resolver);
        }

        return new self($approver->getMorphClass(), $key, $source);
    }

    /**
     * Identity is the morph pair. Two entries that name the same record are the
     * same assignment however differently they were reached.
     */
    public function key(): string
    {
        return $this->type.':'.$this->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'approver_type' => $this->type,
            'approver_id' => $this->id,
            'source' => $this->source,
        ];
    }
}
