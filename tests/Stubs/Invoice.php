<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Tests\Stubs;

use ByRcsc\LaravelApproval\Concerns\Approvable;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;

/**
 * One model carrying every cast the stale check has to survive.
 *
 * The awkward ones are `encrypted` - whose ciphertext differs on every write,
 * so a raw comparison would report drift on an untouched record - and
 * `decimal`, which only agrees with itself once the cast has run.
 */
class Invoice extends Model
{
    use Approvable;

    protected $table = 'invoices';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'sent_at' => 'datetime',
            'status' => InvoiceStatus::class,
            'account_number' => 'encrypted',
            'total' => 'decimal:2',
            'rate' => 'float',
            'quantity' => 'integer',
            'settled' => 'boolean',
            'lines' => 'array',
            'meta' => AsArrayObject::class,
        ];
    }
}
