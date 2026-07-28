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
        foreach (self::keys($model) as $key) {
            if ((self::$bypasses[$key] ?? 0) > 0) {
                return true;
            }
        }

        return false;
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
        $keys = self::keys($model);

        foreach ($keys as $key) {
            self::$bypasses[$key] = (self::$bypasses[$key] ?? 0) + 1;
        }

        try {
            return $callback();
        } finally {
            foreach ($keys as $key) {
                self::$bypasses[$key]--;

                if (self::$bypasses[$key] === 0) {
                    unset(self::$bypasses[$key]);
                }
            }
        }
    }

    /**
     * Both the row the model stands for and the object in hand: the row key so
     * that a second instance of the same record is covered by the same bypass,
     * the object key so that a model which gains its key mid-bypass - it was
     * inserted inside the callback - is not lost track of halfway through.
     *
     * The two live in separate namespaces on purpose. Keying an unsaved model
     * by `spl_object_id` under the morph class, as this once did, lets an
     * object id collide with a real primary key of the same class.
     *
     * @return list<string>
     */
    private static function keys(Model $model): array
    {
        $keys = ['object:'.spl_object_id($model)];
        $key = $model->getKey();

        if (is_scalar($key)) {
            $keys[] = 'row:'.$model->getMorphClass().':'.$key;
        }

        return $keys;
    }
}
