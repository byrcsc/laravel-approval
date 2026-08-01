<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use ByRcsc\LaravelApproval\Concerns\Approvable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Workbench\Database\Factories\PurchaseOrderFactory;

/**
 * The demo's edit-under-approval model.
 *
 * A purchase order exists before it is submitted; what needs approving is the
 * change to it. `demo:submit` passes the proposed `vendor`, `amount`, and
 * `currency` as a draft, which the request holds until the final approval
 * applies it.
 *
 * Two workflows are registered against this type - see the `definitions` block
 * in `WorkbenchServiceProvider` - and which one runs is decided by the amount
 * being proposed, not the amount on the record.
 *
 * This model runs in guard mode, which `WorkbenchServiceProvider` sets through
 * `approval.models`. While a draft is pending, an ordinary `save()` touching a
 * drafted attribute throws; `withoutApprovalGuard()` is the way past it, and
 * the bypass is written to the audit trail.
 */
final class PurchaseOrder extends Model
{
    use Approvable;

    /** @use HasFactory<PurchaseOrderFactory> */
    use HasFactory;

    /** @var array<int, string> */
    protected $guarded = [];

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * `status` is the application's own lifecycle column, moved by the app's
     * listeners on `ApprovalApproved` and friends. Leaving it out of this list
     * means no draft can propose a change to it, however the request was built.
     *
     * @return list<string>|null
     */
    public function approvableAttributes(): ?array
    {
        return ['vendor', 'amount', 'currency'];
    }

    /**
     * Minor units in the column, a readable string everywhere else.
     */
    public function formattedAmount(): string
    {
        $amount = $this->getAttributeValue('amount');
        $currency = $this->getAttributeValue('currency');

        return sprintf(
            '%s %s',
            is_string($currency) ? $currency : '???',
            number_format(((int) (is_numeric($amount) ? $amount : 0)) / 100, 2),
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
        ];
    }
}
