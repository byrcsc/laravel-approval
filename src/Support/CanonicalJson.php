<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Support;

final class CanonicalJson
{
    public static function normalize(mixed $value): mixed
    {
        /** @var mixed $decoded */
        $decoded = json_decode(
            json_encode($value, JSON_THROW_ON_ERROR),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        return self::sortObjectKeys($decoded);
    }

    private static function sortObjectKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::sortObjectKeys(...), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
