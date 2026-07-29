<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Support;

use BackedEnum;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use JsonSerializable;
use Stringable;
use UnitEnum;

/**
 * Answers one question: has the record moved since the draft was written
 * against it?
 *
 * The comparison is made on cast values, never on the raw columns, and that is
 * the whole design. An encrypted attribute produces different ciphertext every
 * time it is written, so comparing raw would report drift on a record nobody
 * touched. A `decimal:2` column comes back as a string on both sides only once
 * the cast has run. Dates, enums, and JSON have the same shape of problem.
 *
 * Both sides are read through the same model class, so the same casts apply to
 * each and only the values differ. Casts that read sibling attributes are the
 * documented limit: the snapshot is merged over the record's own attributes
 * before casting, which covers them as long as the sibling has not also moved.
 */
final class DraftDiffer
{
    /**
     * Which of the snapshotted attributes no longer hold their snapshotted
     * value. Empty means the draft is still safe to apply.
     *
     * @param  array<string, mixed>  $original  raw column values captured at submit time
     * @return list<string>
     */
    public static function drift(Model $model, array $original): array
    {
        $was = self::ghost($model, $original);
        $now = self::ghost($model, $model->getRawOriginal());

        $drifted = [];

        foreach (array_keys($original) as $key) {
            $key = (string) $key;

            if (! self::same($was->getAttributeValue($key), $now->getAttributeValue($key))) {
                $drifted[] = $key;
            }
        }

        return $drifted;
    }

    public static function same(mixed $a, mixed $b): bool
    {
        return self::normalize($a) === self::normalize($b);
    }

    /**
     * A string that two equal values always share and two different values
     * never do. Prefixed by kind, so `null`, `'null'`, and `0` stay distinct.
     */
    public static function normalize(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'bool:'.($value ? '1' : '0'),
            is_int($value) => 'int:'.$value,
            is_float($value) => 'float:'.self::float($value),
            is_string($value) => 'string:'.$value,
            $value instanceof BackedEnum => 'enum:'.$value->value,
            $value instanceof UnitEnum => 'enum:'.$value->name,
            $value instanceof CarbonInterface => 'time:'.$value->getTimestamp().'.'.$value->micro,
            $value instanceof DateTimeInterface => 'time:'.$value->getTimestamp().'.'.$value->format('u'),
            is_array($value) => 'array:'.self::encode($value),
            $value instanceof JsonSerializable => 'array:'.self::encode($value->jsonSerialize()),
            $value instanceof Arrayable => 'array:'.self::encode($value->toArray()),
            $value instanceof Stringable, is_object($value) && method_exists($value, '__toString') => 'string:'.$value,
            default => 'raw:'.serialize($value),
        };
    }

    /**
     * The record as it would have been, had these been its columns.
     *
     * Built on the real model class so the real casts run. The snapshot is laid
     * over the record's own raw attributes rather than replacing them, so a
     * cast that reaches for a sibling column still finds one.
     *
     * @param  array<array-key, mixed>  $attributes
     */
    private static function ghost(Model $model, array $attributes): Model
    {
        $ghost = $model->newInstance([], true);

        $raw = [];

        foreach (array_merge($model->getRawOriginal(), $attributes) as $key => $value) {
            $raw[(string) $key] = $value;
        }

        $ghost->setRawAttributes($raw, true);

        return $ghost;
    }

    /**
     * Key order is not a difference, so it is sorted away before encoding.
     */
    private static function encode(mixed $value): string
    {
        if ($value instanceof JsonSerializable) {
            $value = $value->jsonSerialize();
        } elseif ($value instanceof Arrayable) {
            $value = $value->toArray();
        }

        if (! is_array($value)) {
            return self::normalize($value);
        }

        $normalized = [];

        foreach ($value as $key => $item) {
            $normalized[(string) $key] = is_array($item) || is_object($item)
                ? self::encode($item)
                : self::normalize($item);
        }

        ksort($normalized);

        return (string) json_encode($normalized);
    }

    /**
     * Trailing zeros are not a difference either, and float-to-string is
     * locale-sensitive without the explicit format.
     */
    private static function float(float $value): string
    {
        if (is_nan($value)) {
            return 'nan';
        }

        if (is_infinite($value)) {
            return $value > 0 ? 'inf' : '-inf';
        }

        $formatted = sprintf('%.10F', $value);

        return rtrim(rtrim($formatted, '0'), '.') ?: '0';
    }
}
