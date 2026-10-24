<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Whether the 1.1 delegations migration has run.
 *
 * Upgrading within 1.x must not break an application that has not published
 * and run the new migration yet. Until it has, the engine skips standing
 * delegations entirely, and granting one fails with an instruction instead of
 * a missing-table error. Checked on every call, which costs one schema
 * query per stage activation.
 */
final class DelegationsTable
{
    public static function installed(): bool
    {
        return Schema::hasColumn(TableNames::for('assignments'), 'delegation_id');
    }
}
