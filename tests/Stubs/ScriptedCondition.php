<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Tests\Stubs;

use ByRcsc\LaravelApproval\Contracts\LevelCondition;
use ByRcsc\LaravelApproval\Data\LevelSnapshot;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * A condition the test scripts from outside: what it answers, whether it
 * blows up, and a count of how often the engine actually asked.
 */
final class ScriptedCondition implements LevelCondition
{
    public static bool $result = true;

    public static ?string $throwMessage = null;

    public static int $evaluations = 0;

    public static function reset(): void
    {
        self::$result = true;
        self::$throwMessage = null;
        self::$evaluations = 0;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function passes(LevelSnapshot $level, Model $approvable, Model $submitter, array $config): bool
    {
        self::$evaluations++;

        if (self::$throwMessage !== null) {
            throw new RuntimeException(self::$throwMessage);
        }

        return self::$result;
    }
}
