<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Definitions;

use ByRcsc\LaravelApproval\Contracts\StageCondition;
use ByRcsc\LaravelApproval\Enums\EscalationAction;
use ByRcsc\LaravelApproval\Enums\RejectionPolicy;
use ByRcsc\LaravelApproval\Exceptions\InvalidWorkflowDefinitionException;
use Illuminate\Database\Eloquent\Model;

final readonly class StageDefinition
{
    /**
     * @param  list<ApproverDefinition>  $approvers
     * @param  array<string, mixed>|null  $escalationConfig
     * @param  array<string, mixed>|null  $conditionConfig
     */
    public function __construct(
        public int $sequence,
        public string $name,
        public ?int $requiredApprovals,
        public RejectionPolicy $rejectionPolicy,
        public ?int $slaMinutes,
        public ?EscalationAction $escalationStrategy,
        public ?array $escalationConfig,
        public ?int $remindEveryMinutes,
        public ?string $condition,
        public ?array $conditionConfig,
        public array $approvers,
    ) {
        if ($sequence < 1 || trim($name) === '') {
            throw InvalidWorkflowDefinitionException::because('stage sequences must be positive and stage names cannot be blank.');
        }

        if ($requiredApprovals !== null && $requiredApprovals < 1) {
            throw InvalidWorkflowDefinitionException::because("stage {$sequence} has a non-positive minimum approvals rule.");
        }

        if ($slaMinutes !== null && $slaMinutes < 1) {
            throw InvalidWorkflowDefinitionException::because("stage {$sequence} has a non-positive SLA.");
        }

        if ($remindEveryMinutes !== null && $remindEveryMinutes < 1) {
            throw InvalidWorkflowDefinitionException::because("stage {$sequence} has a non-positive reminder cadence.");
        }

        if ($approvers === []) {
            throw InvalidWorkflowDefinitionException::because("stage {$sequence} has no approvers.");
        }

        if ($condition !== null) {
            if (! class_exists($condition) || ! is_subclass_of($condition, StageCondition::class)) {
                throw InvalidWorkflowDefinitionException::because("condition [{$condition}] does not implement StageCondition.");
            }
        }
    }

    /**
     * @param  array<mixed, mixed>  $value
     */
    public static function from(array $value, int $defaultSequence): self
    {
        $approvers = $value['approvers'] ?? null;

        if (! is_array($approvers)) {
            throw InvalidWorkflowDefinitionException::because("stage {$defaultSequence} must contain an approvers array.");
        }

        $strategy = $value['escalation_strategy'] ?? null;
        $strategy = $strategy instanceof EscalationAction
            ? $strategy
            : (is_string($strategy) ? EscalationAction::tryFrom($strategy) : null);

        if (($value['escalation_strategy'] ?? null) !== null && $strategy === null) {
            throw InvalidWorkflowDefinitionException::because("stage {$defaultSequence} has an unknown escalation strategy.");
        }

        $condition = $value['condition'] ?? null;
        $condition = is_string($condition) && $condition !== '' ? $condition : null;
        $rejectionPolicy = $value['rejection_policy'] ?? RejectionPolicy::ApprovalImpossible;
        $rejectionPolicy = $rejectionPolicy instanceof RejectionPolicy
            ? $rejectionPolicy
            : (is_string($rejectionPolicy) ? RejectionPolicy::tryFrom($rejectionPolicy) : null);

        if ($rejectionPolicy === null) {
            throw InvalidWorkflowDefinitionException::because(
                "stage {$defaultSequence} has an unknown rejection policy."
            );
        }

        return new self(
            sequence: self::integer($value['sequence'] ?? $defaultSequence, 'sequence'),
            name: self::text($value['name'] ?? "Stage {$defaultSequence}", 'name'),
            requiredApprovals: self::nullableInteger($value['required_approvals'] ?? null, 'required_approvals'),
            rejectionPolicy: $rejectionPolicy,
            slaMinutes: self::nullableInteger($value['sla_minutes'] ?? null, 'sla_minutes'),
            escalationStrategy: $strategy,
            escalationConfig: self::nullableArray($value['escalation_config'] ?? null, 'escalation_config'),
            remindEveryMinutes: self::nullableInteger($value['remind_every_minutes'] ?? null, 'remind_every_minutes'),
            condition: $condition,
            conditionConfig: self::nullableArray($value['condition_config'] ?? null, 'condition_config'),
            approvers: array_values(array_map(
                fn (mixed $approver): ApproverDefinition => $approver instanceof Model || is_array($approver)
                    ? ApproverDefinition::from($approver)
                    : throw InvalidWorkflowDefinitionException::because('approvers must be models or arrays.'),
                $approvers,
            )),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return [
            'sequence' => $this->sequence,
            'name' => $this->name,
            'required_approvals' => $this->requiredApprovals,
            'rejection_policy' => $this->rejectionPolicy,
            'sla_minutes' => $this->slaMinutes,
            'escalation_strategy' => $this->escalationStrategy?->value,
            'escalation_config' => $this->escalationConfig,
            'remind_every_minutes' => $this->remindEveryMinutes,
            'condition' => $this->condition,
            'condition_config' => $this->conditionConfig,
        ];
    }

    private static function integer(mixed $value, string $field): int
    {
        if (! is_int($value)) {
            throw InvalidWorkflowDefinitionException::because("{$field} must be an integer.");
        }

        return $value;
    }

    private static function nullableInteger(mixed $value, string $field): ?int
    {
        if ($value === null) {
            return null;
        }

        return self::integer($value, $field);
    }

    private static function text(mixed $value, string $field): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw InvalidWorkflowDefinitionException::because("{$field} cannot be blank.");
        }

        return $value;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function nullableArray(mixed $value, string $field): ?array
    {
        if ($value === null || $value === []) {
            return null;
        }

        if (! is_array($value)) {
            throw InvalidWorkflowDefinitionException::because("{$field} must be an array.");
        }

        $object = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw InvalidWorkflowDefinitionException::because("{$field} must use string keys.");
            }

            $object[$key] = $item;
        }

        return $object;
    }
}
