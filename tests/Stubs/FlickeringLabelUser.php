<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Tests\Stubs;

use Illuminate\Database\Eloquent\Casts\Attribute;

final class FlickeringLabelUser extends User
{
    private int $nameReads = 0;

    /** @return Attribute<non-falsy-string, never> */
    protected function name(): Attribute
    {
        return Attribute::get(function (mixed $value): string {
            $this->nameReads++;
            $name = is_scalar($value) ? (string) $value : 'Unknown';

            return "{$name} read {$this->nameReads}";
        });
    }
}
