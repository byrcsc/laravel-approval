<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Support;

use ByRcsc\LaravelApproval\Contracts\EscalationStrategy;
use ByRcsc\LaravelApproval\Enums\EscalationAction;
use ByRcsc\LaravelApproval\Escalation\AutoDecideStrategy;
use ByRcsc\LaravelApproval\Escalation\EscalateStrategy;
use ByRcsc\LaravelApproval\Escalation\RecordOnlyStrategy;

/**
 * Which class handles which overdue case.
 *
 * {@see TableNames} for the same shape and the same reason: config is the
 * source of truth when it has an answer, and the package's own map is the
 * fallback when a published config predates a case that has since been added.
 */
final class EscalationStrategies
{
    /**
     * @var array<string, class-string<EscalationStrategy>>
     */
    private const DEFAULTS = [
        'record_only' => RecordOnlyStrategy::class,
        'escalate' => EscalateStrategy::class,
        'auto_approve' => AutoDecideStrategy::class,
        'auto_reject' => AutoDecideStrategy::class,
    ];

    /**
     * @return array<string, class-string<EscalationStrategy>>
     */
    public static function defaults(): array
    {
        return self::DEFAULTS;
    }

    /**
     * @return class-string<EscalationStrategy>
     */
    public static function default(EscalationAction $action): string
    {
        return self::DEFAULTS[$action->value];
    }

    /**
     * @return class-string<EscalationStrategy>
     */
    public static function for(EscalationAction $action): string
    {
        /** @var mixed $configured */
        $configured = function_exists('config')
            ? config("approval.escalation.strategies.{$action->value}")
            : null;

        return is_string($configured) && is_subclass_of($configured, EscalationStrategy::class)
            ? $configured
            : self::default($action);
    }
}
