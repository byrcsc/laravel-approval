<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Support;

use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * {@see Payload}, pointed at a model instead of an array.
 *
 * Every model in this package is `$guarded = []` with no typed properties, so
 * static analysis sees `mixed` on the way out of `$model->column`. Reading
 * through here is what turns that back into a type, and it reads the cast value
 * rather than the raw one - the enum, not the string behind it.
 */
final class Attributes
{
    public static function string(Model $model, string $key, string $default = ''): string
    {
        return Payload::string(self::wrap($model, $key), $key, $default);
    }

    public static function nullableString(Model $model, string $key): ?string
    {
        return Payload::nullableString(self::wrap($model, $key), $key);
    }

    public static function int(Model $model, string $key, int $default = 0): int
    {
        return Payload::int(self::wrap($model, $key), $key, $default);
    }

    public static function nullableInt(Model $model, string $key): ?int
    {
        return Payload::nullableInt(self::wrap($model, $key), $key);
    }

    public static function bool(Model $model, string $key, bool $default = false): bool
    {
        return Payload::bool(self::wrap($model, $key), $key, $default);
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function array(Model $model, string $key): array
    {
        return Payload::array(self::wrap($model, $key), $key);
    }

    /**
     * @return array<string, mixed>
     */
    public static function object(Model $model, string $key): array
    {
        return Payload::object(self::wrap($model, $key), $key) ?? [];
    }

    /**
     * Handles both sides of a cast: an enum instance once Eloquent has cast the
     * column, and the bare string when it has not been told to.
     *
     * @template TEnum of BackedEnum
     *
     * @param  class-string<TEnum>  $enum
     * @return TEnum|null
     */
    public static function enum(Model $model, string $key, string $enum): ?BackedEnum
    {
        $value = $model->getAttributeValue($key);

        if ($value instanceof $enum) {
            return $value;
        }

        return is_string($value) ? $enum::tryFrom($value) : null;
    }

    public static function datetime(Model $model, string $key): ?CarbonInterface
    {
        $value = $model->getAttributeValue($key);

        return $value instanceof CarbonInterface ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function wrap(Model $model, string $key): array
    {
        return [$key => $model->getAttributeValue($key)];
    }
}
