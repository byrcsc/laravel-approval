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
        'workflow_stages' => 'approval_workflow_stages',
        'workflow_stage_approvers' => 'approval_workflow_stage_approvers',
        'requests' => 'approval_requests',
        'request_stages' => 'approval_request_stages',
        'assignments' => 'approval_request_assignments',
        'actions' => 'approval_actions',
        'attachments' => 'approval_attachments',
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
