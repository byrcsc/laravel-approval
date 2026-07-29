<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Definitions;

use ByRcsc\LaravelApproval\Exceptions\InvalidWorkflowDefinitionException;

final readonly class WorkflowDefinition
{
    /**
     * @param  array<string, mixed>|null  $settings
     * @param  list<LevelDefinition>  $levels
     */
    public function __construct(
        public string $slug,
        public string $name,
        public ?string $description,
        public bool $active,
        public ?array $settings,
        public array $levels,
    ) {
        if (trim($slug) === '' || trim($name) === '') {
            throw InvalidWorkflowDefinitionException::because('workflow slugs and names cannot be blank.');
        }

        $sequences = array_map(fn (LevelDefinition $level): int => $level->sequence, $levels);

        if ($levels === [] || count($sequences) !== count(array_unique($sequences))) {
            throw InvalidWorkflowDefinitionException::because("workflow [{$slug}] needs levels with unique sequences.");
        }

        sort($sequences);

        if ($sequences !== range(1, count($sequences))) {
            throw InvalidWorkflowDefinitionException::because("workflow [{$slug}] level sequences must be contiguous from one.");
        }
    }

    /**
     * @param  array<mixed, mixed>  $value
     */
    public static function from(array $value, ?string $key = null): self
    {
        $slug = $value['slug'] ?? $key;
        $name = $value['name'] ?? $slug;
        $levels = $value['levels'] ?? null;

        if (! is_string($slug) || ! is_string($name) || ! is_array($levels)) {
            throw InvalidWorkflowDefinitionException::because('a workflow needs a slug, name, and levels array.');
        }

        $settings = $value['settings'] ?? null;

        if ($settings !== null && ! is_array($settings)) {
            throw InvalidWorkflowDefinitionException::because("workflow [{$slug}] settings must be an array.");
        }

        $definitions = [];

        foreach (array_values($levels) as $index => $level) {
            if (! is_array($level)) {
                throw InvalidWorkflowDefinitionException::because("workflow [{$slug}] levels must be arrays.");
            }

            $definitions[] = LevelDefinition::from($level, $index + 1);
        }

        usort($definitions, fn (LevelDefinition $left, LevelDefinition $right): int => $left->sequence <=> $right->sequence);

        $settings = $settings === null || $settings === [] ? null : self::object($settings, $slug);

        return new self(
            slug: $slug,
            name: $name,
            description: is_string($value['description'] ?? null) ? $value['description'] : null,
            active: is_bool($value['active'] ?? true) ? $value['active'] ?? true : true,
            settings: $settings,
            levels: $definitions,
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
            'settings' => $this->settings,
        ];
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
