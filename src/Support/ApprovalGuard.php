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
        $key = self::key($model);

        return $key !== null && (self::$bypasses[$key] ?? 0) > 0;
    }

    /**
     * Re-entrant: the count is what lets a nested bypass close without
     * disarming the one around it.
     *
     * @template TValue
     *
     * @param  callable(): TValue  $callback
     * @return TValue
     */
    public static function without(Model $model, callable $callback): mixed
    {
        $key = self::key($model);

        if ($key === null) {
            return $callback();
        }

        self::$bypasses[$key] = (self::$bypasses[$key] ?? 0) + 1;

        try {
            return $callback();
        } finally {
            if (--self::$bypasses[$key] === 0) {
                unset(self::$bypasses[$key]);
            }
        }
    }

    /**
     * The row the model stands for, so that a second instance of the same
     * record is covered by the same bypass. A model without a key has no row
     * to bypass, and the guard ignores unsaved models anyway.
     */
    private static function key(Model $model): ?string
    {
        $key = $model->getKey();

        return is_scalar($key) ? $model->getMorphClass().':'.$key : null;
    }
}
