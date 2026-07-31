<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Definitions;

use ByRcsc\LaravelApproval\Contracts\WorkflowCondition;
use ByRcsc\LaravelApproval\Exceptions\InvalidWorkflowDefinitionException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

final readonly class WorkflowDefinition
{
    /**
     * @param  array<string, mixed>|null  $settings
     * @param  array<string, mixed>|null  $conditionConfig
     * @param  list<StageDefinition>  $stages
     */
    public function __construct(
        public string $slug,
        public string $name,
        public ?string $description,
        public bool $active,
        public ?string $approvableType,
        public ?string $condition,
        public ?array $conditionConfig,
        public int $priority,
        public ?array $settings,
        public array $stages,
    ) {
        if (trim($slug) === '' || trim($name) === '') {
            throw InvalidWorkflowDefinitionException::because('workflow slugs and names cannot be blank.');
        }

        if ($condition !== null && (! class_exists($condition) || ! is_subclass_of($condition, WorkflowCondition::class))) {
            throw InvalidWorkflowDefinitionException::because("condition [{$condition}] does not implement WorkflowCondition.");
        }

        if ($priority < 0) {
            throw InvalidWorkflowDefinitionException::because("workflow [{$slug}] cannot have a negative priority.");
        }

        $sequences = array_map(fn (StageDefinition $stage): int => $stage->sequence, $stages);

        if ($stages === [] || count($sequences) !== count(array_unique($sequences))) {
            throw InvalidWorkflowDefinitionException::because("workflow [{$slug}] needs stages with unique sequences.");
        }

        sort($sequences);

        if ($sequences !== range(1, count($sequences))) {
            throw InvalidWorkflowDefinitionException::because("workflow [{$slug}] stage sequences must be contiguous from one.");
        }
    }

    /**
     * @param  array<mixed, mixed>  $value
     */
    public static function from(array $value, ?string $key = null): self
    {
        $slug = $value['slug'] ?? $key;
        $name = $value['name'] ?? $slug;
        $stages = $value['stages'] ?? null;

        if (! is_string($slug) || ! is_string($name) || ! is_array($stages)) {
            throw InvalidWorkflowDefinitionException::because('a workflow needs a slug, name, and stages array.');
        }

        $settings = $value['settings'] ?? null;

        if ($settings !== null && ! is_array($settings)) {
            throw InvalidWorkflowDefinitionException::because("workflow [{$slug}] settings must be an array.");
        }

        $definitions = [];

        foreach (array_values($stages) as $index => $stage) {
            if (! is_array($stage)) {
                throw InvalidWorkflowDefinitionException::because("workflow [{$slug}] stages must be arrays.");
            }

            $definitions[] = StageDefinition::from($stage, $index + 1);
        }

        usort($definitions, fn (StageDefinition $left, StageDefinition $right): int => $left->sequence <=> $right->sequence);

        $settings = $settings === null || $settings === [] ? null : self::object($settings, $slug);

        $conditionConfig = $value['condition_config'] ?? null;

        if ($conditionConfig !== null && ! is_array($conditionConfig)) {
            throw InvalidWorkflowDefinitionException::because("workflow [{$slug}] condition_config must be an array.");
        }

        $priority = $value['priority'] ?? 0;

        if (! is_int($priority)) {
            throw InvalidWorkflowDefinitionException::because("workflow [{$slug}] priority must be an integer.");
        }

        return new self(
            slug: $slug,
            name: $name,
            description: is_string($value['description'] ?? null) ? $value['description'] : null,
            active: is_bool($value['active'] ?? true) ? $value['active'] ?? true : true,
            approvableType: self::morphType($value['approvable_type'] ?? null),
            condition: is_string($value['condition'] ?? null) && $value['condition'] !== '' ? $value['condition'] : null,
            conditionConfig: $conditionConfig === null || $conditionConfig === [] ? null : self::object($conditionConfig, $slug),
            priority: $priority,
            settings: $settings,
            stages: $definitions,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'active' => $this->active,
            'approvable_type' => $this->approvableType,
            'condition' => $this->condition,
            'condition_config' => $this->conditionConfig,
            'priority' => $this->priority,
            'settings' => $this->settings,
        ];
    }

    /**
     * The morph form of the approvable class, so a definition may name either
     * and the column holds what a request would record.
     */
    private static function morphType(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $alias = is_a($value, Model::class, true) ? Relation::getMorphAlias($value) : null;

        return is_string($alias) ? $alias : $value;
    }

    /**
     * @param  array<mixed, mixed>  $value
     * @return array<string, mixed>
     */
    private static function object(array $value, string $slug): array
    {
        $object = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw InvalidWorkflowDefinitionException::because("workflow [{$slug}] settings must use string keys.");
            }

            $object[$key] = $item;
        }

        return $object;
    }
}
