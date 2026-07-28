<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Models;

use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Support\TableNames;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * One line of the audit trail, append-only and enforced as such.
 *
 * Rewriting one is a bug in the calling code rather than a condition to handle,
 * hence `LogicException` rather than one of this package's own exceptions.
 * Corrections work the way they do on paper: by appending another action.
 */
final class ApprovalAction extends Model
{
    /** Rows are written once, so there is nothing an `updated_at` could mean. */
    public const UPDATED_AT = null;

    protected $guarded = [];

    public function getTable(): string
    {
        return TableNames::for('actions');
    }

    protected static function booted(): void
    {
        self::updating(function (self $action): void {
            throw new LogicException(
                "Approval actions are an immutable audit trail and cannot be updated. Append a new action instead. [{$action->describe()}]"
            );
        });

        self::deleting(function (self $action): void {
            throw new LogicException(
                "Approval actions are an immutable audit trail and cannot be deleted. [{$action->describe()}]"
            );
        });
    }

    private function describe(): string
    {
        $key = $this->getKey();

        return $this->getTable().'#'.(is_scalar($key) ? (string) $key : 'unsaved');
    }

    /**
     * @return BelongsTo<ApprovalRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'request_id');
    }

    /**
     * Null for actions that belong to the request as a whole - submitting it,
     * cancelling it - rather than to any one level.
     *
     * @return BelongsTo<ApprovalRequestStep, $this>
     */
    public function step(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequestStep::class, 'step_id');
    }

    /**
     * Null when the system acted on nobody's behalf.
     *
     * @return MorphTo<Model, $this>
     */
    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => ActionType::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
