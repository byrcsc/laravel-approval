<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Tests\Stubs;

use ByRcsc\LaravelApproval\Concerns\Approvable;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflow;
use Illuminate\Database\Eloquent\Model;

/**
 * The trait with every seam overridden - the shape a host app that has thought
 * about it ends up with.
 */
class PurchaseOrder extends Model
{
    use Approvable;

    /**
     * Set by tests to stand in for a request's authenticated user.
     */
    public static ?Model $actingRequester = null;

    public static string $workflowSlug = 'purchase-order';

    protected $table = 'purchase_orders';

    protected $guarded = [];

    public function approvalWorkflow(): ApprovalWorkflow|string|null
    {
        return self::$workflowSlug;
    }

    /**
     * `status` is deliberately absent: it is moved by the engine and by
     * fulfilment, and letting somebody draft it would be letting them approve
     * their way around both.
     *
     * @return list<string>|null
     */
    public function approvableAttributes(): ?array
    {
        return ['reference', 'amount'];
    }

    public function approvalRequester(): ?Model
    {
        return self::$actingRequester;
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
