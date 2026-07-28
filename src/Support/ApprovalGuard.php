<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * A process-local, narrowly scoped bypass used while the engine applies an
 * approved draft and by the explicit audited escape hatch.
 */
final class ApprovalGuard
{
    /** @var array<string, int> */
    private static array $bypasses = [];

    public static function bypassing(Model $model): bool
    {
        return (self::$bypasses[self::key($model)] ?? 0) > 0;
    }

    /**
     * @template TValue
     *
     * @param  callable(): TValue  $callback
     * @return TValue
     */
    public static function without(Model $model, callable $callback): mixed
    {
        $key = self::key($model);
        self::$bypasses[$key] = (self::$bypasses[$key] ?? 0) + 1;

        try {
            return $callback();
        } finally {
            self::$bypasses[$key]--;

            if (self::$bypasses[$key] === 0) {
                unset(self::$bypasses[$key]);
            }
        }
    }

    private static function key(Model $model): string
    {
        $key = $model->getKey();

        return $model->getMorphClass().':'.(is_scalar($key) ? (string) $key : spl_object_id($model));
    }
}
