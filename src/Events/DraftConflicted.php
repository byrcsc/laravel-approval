<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Events;

use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use Illuminate\Database\Eloquent\Model;

/**
 * Every stage approved, and then the draft could not be applied: the record had
 * moved underneath it. Nothing was written.
 *
 * `$attributes` names what moved. `$approvable` is null when the record itself
 * is gone, which is the other way this happens.
 *
 * This is the event to listen for if somebody should be told to go and look -
 * a conflicted request sits there until a person resolves it.
 */
final class DraftConflicted extends ApprovalEvent
{
    /**
     * @param  list<string>  $attributes
     */
    public function __construct(
        ApprovalRequest $request,
        public readonly ?Model $approvable,
        public readonly array $attributes,
    ) {
        parent::__construct($request);
    }
}
