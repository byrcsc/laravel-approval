<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Data;

use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Support\Attributes;
use ByRcsc\LaravelApproval\Support\DraftDiffer;

/**
 * The proposed edit, and the values it was proposed against.
 *
 * `original` is raw column data rather than cast values, because that is what
 * survives a round trip through a JSON column unchanged. Making sense of it is
 * {@see DraftDiffer}'s job.
 */
final readonly class Draft
{
    /**
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>  $original
     */
    public function __construct(
        public array $new,
        public array $original,
    ) {}

    /**
     * Null when the request gates the record itself rather than an edit to it -
     * there is nothing to apply, and that is a different case from an edit that
     * happens to be empty.
     */
    public static function fromRequest(ApprovalRequest $request): ?self
    {
        if ($request->getAttributeValue('new_attributes') === null) {
            return null;
        }

        return new self(
            new: Attributes::object($request, 'new_attributes'),
            original: Attributes::object($request, 'original_attributes'),
        );
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map(strval(...), array_keys($this->new));
    }

    /**
     * What the edit would do, in a shape worth putting in an audit log or a
     * diff view.
     *
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public function changes(): array
    {
        $changes = [];

        foreach ($this->new as $key => $value) {
            $changes[$key] = ['from' => $this->original[$key] ?? null, 'to' => $value];
        }

        return $changes;
    }
}
