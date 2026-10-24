<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Models;

use ByRcsc\LaravelApproval\ApprovalModels;
use ByRcsc\LaravelApproval\Database\Factories\ApprovalActionFactory;
use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Support\TableNames;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * One entry in the append-only action history.
 *
 * Append-only is enforced by this model, which is an application-level
 * guarantee: it blocks updates and deletes made through Eloquent. A direct
 * query-builder or SQL write bypasses it, and detecting that is the job of the
 * hash chain and `approval:verify`. Corrections are made by appending another
 * action rather than editing one.
 *
 * Attempting to rewrite an entry is a bug in the calling code rather than a
 * runtime condition to handle, hence `LogicException` rather than one of this
 * package's own exceptions.
 */
class ApprovalAction extends Model
{
    /** @use HasFactory<ApprovalActionFactory> */
    use HasFactory;

    /** Rows are written once, so there is nothing an `updated_at` could mean. */
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected static function newFactory(): ApprovalActionFactory
    {
        return ApprovalActionFactory::new();
    }

    public function getTable(): string
    {
        return TableNames::for('actions');
    }

    protected static function booted(): void
    {
        self::updating(function (self $action): void {
            throw new LogicException(
                "Approval actions are an append-only history and cannot be updated. Append a new action instead. [{$action->describe()}]"
            );
        });

        self::deleting(function (self $action): void {
            throw new LogicException(
                "Approval actions are an append-only history and cannot be deleted. [{$action->describe()}]"
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
        return $this->belongsTo(ApprovalModels::request(), 'request_id');
    }

    /**
     * Null for actions that belong to the request as a whole - submitting it,
     * cancelling it - rather than to any one stage.
     *
     * @return BelongsTo<ApprovalRequestStage, $this>
     */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(ApprovalModels::requestStage(), 'stage_id');
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
     * The files put in evidence of this decision - a signature image, say.
     *
     * @return HasMany<ApprovalAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(ApprovalModels::attachment(), 'action_id')
            ->orderBy('id');
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
