<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval;

use ByRcsc\LaravelApproval\Exceptions\InvalidConfigurationException;
use ByRcsc\LaravelApproval\Models\ApprovalAction;
use ByRcsc\LaravelApproval\Models\ApprovalAttachment;
use ByRcsc\LaravelApproval\Models\ApprovalDelegation;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Models\ApprovalRequestAssignment;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStage;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflow;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflowStage;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflowStageApprover;
use Illuminate\Database\Eloquent\Model;

/**
 * Which Eloquent class the package uses for each of its tables.
 *
 * `approval.model_classes` may name a subclass of any package model, keyed like
 * `approval.table_names`. Every query, relation, and write in the package goes
 * through here, so a subclass's scopes, casts, and relations apply everywhere.
 * A configured class must extend the package model it replaces, because the
 * engine relies on the behavior the base class defines, such as the append-only
 * guard on actions and attachments.
 */
final class ApprovalModels
{
    /**
     * @var array<string, class-string<Model>>
     */
    private const DEFAULTS = [
        'workflows' => ApprovalWorkflow::class,
        'workflow_stages' => ApprovalWorkflowStage::class,
        'workflow_stage_approvers' => ApprovalWorkflowStageApprover::class,
        'requests' => ApprovalRequest::class,
        'request_stages' => ApprovalRequestStage::class,
        'assignments' => ApprovalRequestAssignment::class,
        'actions' => ApprovalAction::class,
        'attachments' => ApprovalAttachment::class,
        'delegations' => ApprovalDelegation::class,
    ];

    /** @return class-string<ApprovalWorkflow> */
    public static function workflow(): string
    {
        /** @var class-string<ApprovalWorkflow> */
        return self::resolve('workflows');
    }

    /** @return class-string<ApprovalWorkflowStage> */
    public static function workflowStage(): string
    {
        /** @var class-string<ApprovalWorkflowStage> */
        return self::resolve('workflow_stages');
    }

    /** @return class-string<ApprovalWorkflowStageApprover> */
    public static function workflowStageApprover(): string
    {
        /** @var class-string<ApprovalWorkflowStageApprover> */
        return self::resolve('workflow_stage_approvers');
    }

    /** @return class-string<ApprovalRequest> */
    public static function request(): string
    {
        /** @var class-string<ApprovalRequest> */
        return self::resolve('requests');
    }

    /** @return class-string<ApprovalRequestStage> */
    public static function requestStage(): string
    {
        /** @var class-string<ApprovalRequestStage> */
        return self::resolve('request_stages');
    }

    /** @return class-string<ApprovalRequestAssignment> */
    public static function assignment(): string
    {
        /** @var class-string<ApprovalRequestAssignment> */
        return self::resolve('assignments');
    }

    /** @return class-string<ApprovalAction> */
    public static function action(): string
    {
        /** @var class-string<ApprovalAction> */
        return self::resolve('actions');
    }

    /** @return class-string<ApprovalAttachment> */
    public static function attachment(): string
    {
        /** @var class-string<ApprovalAttachment> */
        return self::resolve('attachments');
    }

    /** @return class-string<ApprovalDelegation> */
    public static function delegation(): string
    {
        /** @var class-string<ApprovalDelegation> */
        return self::resolve('delegations');
    }

    /**
     * Boot-time check of the published config. An absent key keeps the package
     * model; a present one must name a subclass of it.
     *
     * @param  array<string, mixed>  $configured
     */
    public static function validate(array $configured): void
    {
        foreach ($configured as $key => $class) {
            $base = self::DEFAULTS[$key] ?? throw InvalidConfigurationException::unknownModelClassKey($key);

            if ($class === null || $class === $base) {
                continue;
            }

            if (! is_string($class) || ! is_a($class, $base, true)) {
                throw InvalidConfigurationException::modelClassMustExtend($key, $class, $base);
            }
        }
    }

    /**
     * Classes that differ from the package default, for `php artisan about`.
     *
     * @return array<string, class-string<Model>>
     */
    public static function overridden(): array
    {
        $overridden = [];

        foreach (array_keys(self::DEFAULTS) as $key) {
            $class = self::resolve($key);

            if ($class !== self::DEFAULTS[$key]) {
                $overridden[$key] = $class;
            }
        }

        return $overridden;
    }

    /**
     * @return class-string<Model>
     */
    private static function resolve(string $key): string
    {
        /** @var mixed $configured */
        $configured = config("approval.model_classes.{$key}");

        return is_string($configured) && is_a($configured, self::DEFAULTS[$key], true)
            ? $configured
            : self::DEFAULTS[$key];
    }
}
