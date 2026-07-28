<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Support;

use BackedEnum;

/**
 * Type-safe readers for the arrays this package does not control: published
 * config, and JSON columns written by an older version of itself.
 *
 * Every reader degrades to a default rather than throwing. Deciding that a
 * value should instead be loud is the caller's job, never this class's.
 */
final class Payload
{
    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function string(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? null;

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function int(array $data, string $key, int $default = 0): int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function nullableInt(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Accepts the `1`/`0` and `"true"`/`"false"` forms an `.env` file yields.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function bool(array $data, string $key, bool $default = false): bool
    {
        $value = $data[$key] ?? null;

        return match (true) {
            is_bool($value) => $value,
            $value === 1, $value === '1', $value === 'true' => true,
            $value === 0, $value === '0', $value === 'false' => false,
            default => $default,
        };
    }

    /**
     * As {@see self::bool()}, but yields `null` rather than a default, so "not
     * set" stays distinct from `false`.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function nullableBool(array $data, string $key): ?bool
    {
        $value = $data[$key] ?? null;

        return match (true) {
            is_bool($value) => $value,
            $value === 1, $value === '1', $value === 'true' => true,
            $value === 0, $value === '0', $value === 'false' => false,
            default => null,
        };
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function array(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    /**
     * A JSON list is rejected: callers of this want an object.
     *
     * @return array<string, mixed>|null
     */
    public static function asObject(mixed $value): ?array
    {
        if (! is_array($value) || array_is_list($value)) {
            return null;
        }

        $object = [];

        foreach ($value as $key => $item) {
            $object[(string) $key] = $item;
        }

        return $object;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<string, mixed>|null
     */
    public static function object(array $data, string $key): ?array
    {
        return self::asObject($data[$key] ?? null);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<string>
     */
    public static function strings(array $data, string $key): array
    {
        $strings = [];

        foreach (self::array($data, $key) as $value) {
            if (is_scalar($value)) {
                $strings[] = (string) $value;
            }
        }

        return $strings;
    }

    /**
     * The name of a class that exists, or null. A class-string in config is a
     * promise the host app makes; this is where it gets tested.
     *
     * @param  array<array-key, mixed>  $data
     * @return class-string|null
     */
    public static function classString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && class_exists($value) ? $value : null;
    }

    /**
     * Yields null for cases this package has not enumerated, rather than
     * throwing on an unknown value.
     *
     * @template TEnum of BackedEnum
     *
     * @param  array<array-key, mixed>  $data
     * @param  class-string<TEnum>  $enum
     * @return TEnum|null
     */
    public static function enum(array $data, string $key, string $enum): ?BackedEnum
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $enum::tryFrom($value) : null;
    }
}
