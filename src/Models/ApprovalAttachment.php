<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Models;

use ByRcsc\LaravelApproval\ApprovalModels;
use ByRcsc\LaravelApproval\Database\Factories\ApprovalAttachmentFactory;
use ByRcsc\LaravelApproval\Support\TableNames;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * A reference to a file put in evidence: a supporting document on the request,
 * or a signature on the decision its `action_id` points at.
 *
 * A reference only. `disk` and `path` name a location on a host-owned
 * filesystem disk, and the file's bytes never pass through this package. The
 * `checksum` the host supplies at attach time is recorded in the action that
 * logs the attachment, so the recorded checksum is covered by the hash chain.
 * Verification compares the attachment row against that recorded metadata; it
 * does not read the stored file or recompute its checksum.
 *
 * Append-only, like the action history it is evidence for. A file attached in
 * error is answered by appending another action, not by editing history.
 */
class ApprovalAttachment extends Model
{
    /** @use HasFactory<ApprovalAttachmentFactory> */
    use HasFactory;

    /** Rows are written once, so there is nothing an `updated_at` could mean. */
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected static function newFactory(): ApprovalAttachmentFactory
    {
        return ApprovalAttachmentFactory::new();
    }

    public function getTable(): string
    {
        return TableNames::for('attachments');
    }

    protected static function booted(): void
    {
        self::updating(function (self $attachment): void {
            throw new LogicException(
                "Approval attachments are evidence and cannot be updated. Attach a corrected file instead. [{$attachment->describe()}]"
            );
        });

        self::deleting(function (self $attachment): void {
            throw new LogicException(
                "Approval attachments are evidence and cannot be deleted. [{$attachment->describe()}]"
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
     * The decision this file supports, or null for a document that belongs to
     * the request as a whole.
     *
     * @return BelongsTo<ApprovalAction, $this>
     */
    public function action(): BelongsTo
    {
        return $this->belongsTo(ApprovalModels::action(), 'action_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function uploader(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
