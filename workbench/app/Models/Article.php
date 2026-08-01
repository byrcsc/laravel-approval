<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use ByRcsc\LaravelApproval\Concerns\Approvable;
use ByRcsc\LaravelApproval\Contracts\AutoSubmitsForApproval;
use ByRcsc\LaravelApproval\Data\AutoSubmission;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Workbench\Database\Factories\ArticleFactory;

/**
 * The demo's record-under-approval model, and its three counterpoints to
 * {@see PurchaseOrder}.
 *
 * **Record-level.** No draft is submitted. The article is what needs approving,
 * so approving the request changes the request and nothing else: `published`
 * stays false until the app's own listener on `ApprovalApproved` sets it. That
 * listener lives in `WorkbenchServiceProvider`, because publication is the
 * application's state machine, not the package's.
 *
 * **Automatic submission.** Implementing `AutoSubmitsForApproval` submits the
 * article once it has been created. It does not gate creation - the row exists
 * either way - so `Article::create()` returns a saved, unapproved article.
 *
 * **Soft deletes.** A soft-deleted record is recoverable, so the package leaves
 * its open request alone by default. An article that is deleted is not coming
 * back into the same review, so this one closes it.
 *
 * Its workflow comes from the `approval.workflows` config map rather than from
 * a method here. Overriding `approvalWorkflow()` is the other seam:
 *
 *     public function approvalWorkflow(): ApprovalWorkflow|string|null
 *     {
 *         return 'article-review';
 *     }
 */
final class Article extends Model implements AutoSubmitsForApproval
{
    use Approvable;

    /** @use HasFactory<ArticleFactory> */
    use HasFactory;

    use SoftDeletes;

    /** @var array<int, string> */
    protected $guarded = [];

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * What automatic submission submits. No draft, and the author rather than
     * whoever happens to be authenticated - an article seeded by a console
     * command has no authenticated user at all.
     */
    public function approvalAutoSubmission(): AutoSubmission
    {
        return new AutoSubmission(
            requester: $this->author,
            comment: 'Submitted automatically on creation.',
        );
    }

    /**
     * Also consulted by an explicit `submitForApproval()` with no requester, so
     * the two paths attribute the request to the same person.
     */
    public function approvalRequester(): ?Model
    {
        return $this->author;
    }

    public function closeApprovalOnSoftDelete(): bool
    {
        return true;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published' => 'boolean',
        ];
    }
}
