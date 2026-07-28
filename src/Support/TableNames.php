<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Support;

/**
 * The single source of truth for what this package's tables are called: the
 * models resolve through here, and boot-time validation checks the published
 * config against the same map.
 */
final class TableNames
{
    /**
     * @var array<string, string>
     */
    private const DEFAULTS = [
        'workflows' => 'approval_workflows',
        'levels' => 'approval_workflow_levels',
        'level_approvers' => 'approval_workflow_level_approvers',
        'requests' => 'approval_requests',
        'steps' => 'approval_request_steps',
        'step_approvers' => 'approval_request_step_approvers',
        'actions' => 'approval_actions',
    ];

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::DEFAULTS);
    }

    /**
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return self::DEFAULTS;
    }

    public static function default(string $key): string
    {
        return self::DEFAULTS[$key] ?? $key;
    }

    /**
     * The fallback covers a published config written against an older version
     * of the package, with no entry for a table added since.
     */
    public static function for(string $key): string
    {
        /** @var mixed $configured */
        $configured = function_exists('config')
            ? config("approval.table_names.{$key}")
            : null;

        return is_string($configured) && $configured !== ''
            ? $configured
            : self::default($key);
    }
}
