<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Data;

use ByRcsc\LaravelApproval\Enums\EscalationAction;
use ByRcsc\LaravelApproval\Enums\RejectionPolicy;
use ByRcsc\LaravelApproval\Enums\StageStatus;
use ByRcsc\LaravelApproval\Models\ApprovalRequestStage;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflowStage;
use ByRcsc\LaravelApproval\Support\Attributes;

/**
 * A workflow stage, lifted out of the definition so it can be frozen onto a
 * request. This is the only thing that crosses from the editable side of the
 * schema to the immutable one, which is what makes "edit the workflow, in-flight
 * requests keep their old rules" true rather than aspirational.
 */
final readonly class StageSnapshot
{
    /**
     * @param  array<string, mixed>  $escalationConfig
     * @param  array<string, mixed>  $conditionConfig
     */
    public function __construct(
        public int $sequence,
        public string $name,
        public ?int $requiredApprovals,
        public RejectionPolicy $rejectionPolicy,
        public ?int $slaMinutes,
        public ?EscalationAction $escalationStrategy,
        public array $escalationConfig = [],
        public ?int $remindEveryMinutes = null,
        public ?string $condition = null,
        public array $conditionConfig = [],
        public bool $conditionPassed = true,
    ) {}

    public static function fromWorkflowStage(ApprovalWorkflowStage $stage): self
    {
        return self::fromColumns($stage);
    }

    /**
     * Workflow stages and request stages carry the same snapshot columns, so
     * both constructors read them through here.
     */
    private static function fromColumns(ApprovalWorkflowStage|ApprovalRequestStage $stage): self
    {
        return new self(
            sequence: Attributes::int($stage, 'sequence'),
            name: Attributes::string($stage, 'name'),
            requiredApprovals: Attributes::nullableInt($stage, 'required_approvals'),
            rejectionPolicy: self::rejectionPolicy($stage),
            slaMinutes: Attributes::nullableInt($stage, 'sla_minutes'),
            escalationStrategy: Attributes::enum($stage, 'escalation_strategy', EscalationAction::class),
            escalationConfig: Attributes::object($stage, 'escalation_config'),
            remindEveryMinutes: Attributes::nullableInt($stage, 'remind_every_minutes'),
            condition: Attributes::nullableString($stage, 'condition'),
            conditionConfig: Attributes::object($stage, 'condition_config'),
        );
    }

    /**
     * Reads the snapshot back off a stage, so code that already has the frozen
     * row does not have to reach for the stage it came from.
     */
    public static function fromRequestStage(ApprovalRequestStage $stage): self
    {
        return self::fromColumns($stage)
            ->withConditionResult(Attributes::bool($stage, 'condition_result'));
    }

    public function withConditionResult(bool $passed): self
    {
        return new self(
            sequence: $this->sequence,
            name: $this->name,
            requiredApprovals: $this->requiredApprovals,
            rejectionPolicy: $this->rejectionPolicy,
            slaMinutes: $this->slaMinutes,
            escalationStrategy: $this->escalationStrategy,
            escalationConfig: $this->escalationConfig,
            remindEveryMinutes: $this->remindEveryMinutes,
            condition: $this->condition,
            conditionConfig: $this->conditionConfig,
            conditionPassed: $passed,
        );
    }

    /**
     * How many approvals this stage needs, given how many assignments it ended up
     * with. A null rule means all of them, which can only be answered once
     * the resolvers have run.
     */
    public function requiredApprovalsFor(int $assignments): int
    {
        return $this->requiredApprovals ?? $assignments;
    }

    /**
     * Stages are born queued; activating one is a transition of its own.
     *
     * @return array<string, mixed>
     */
    public function toStageAttributes(): array
    {
        return [
            'sequence' => $this->sequence,
            'name' => $this->name,
            'required_approvals' => $this->requiredApprovals,
            'rejection_policy' => $this->rejectionPolicy,
            'sla_minutes' => $this->slaMinutes,
            'escalation_strategy' => $this->escalationStrategy,
            'escalation_config' => $this->escalationConfig === [] ? null : $this->escalationConfig,
            'remind_every_minutes' => $this->remindEveryMinutes,
            'condition' => $this->condition,
            'condition_config' => $this->conditionConfig === [] ? null : $this->conditionConfig,
            'condition_result' => $this->conditionPassed,
            'state' => $this->conditionPassed ? StageStatus::Queued : StageStatus::Skipped,
        ];
    }

    private static function rejectionPolicy(ApprovalWorkflowStage|ApprovalRequestStage $stage): RejectionPolicy
    {
        $value = $stage->getRawOriginal('rejection_policy');

        if ($value === null) {
            return RejectionPolicy::ApprovalImpossible;
        }

        if (! is_string($value)) {
            throw new \UnexpectedValueException('The rejection policy must be a string.');
        }

        return RejectionPolicy::tryFrom($value)
            ?? throw new \UnexpectedValueException("Unknown rejection policy [{$value}].");
    }
}
