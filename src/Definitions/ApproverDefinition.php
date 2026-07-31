<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Definitions;

use ByRcsc\LaravelApproval\Contracts\ApproverResolver;
use ByRcsc\LaravelApproval\Exceptions\InvalidWorkflowDefinitionException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * One approver rule on a stage definition: either a concrete approver or a
 * resolver that produces them.
 *
 * A concrete approver is an exact actor identity - the persisted model named
 * here is the only identity that may decide. Container models such as a Team,
 * Role, or Position are never expanded into their members; assigning one assigns
 * the container itself. To assign the members, use an approver resolver, which
 * must return persisted actor models. Both forms are validated here, so an
 * unreachable approver fails at definition time rather than at submission.
 */
final readonly class ApproverDefinition
{
    /**
     * @param  array<string, mixed>|null  $resolverConfig
     */
    private function __construct(
        public ?string $approverType,
        public int|string|null $approverId,
        public ?string $resolver,
        public ?array $resolverConfig,
    ) {}

    /**
     * @param  Model|array<mixed, mixed>  $value
     */
    public static function from(Model|array $value): self
    {
        if ($value instanceof Model) {
            $key = $value->getKey();

            if (! $value->exists || ! is_int($key) && ! is_string($key)) {
                throw InvalidWorkflowDefinitionException::because('concrete approvers must be persisted models.');
            }

            return new self($value->getMorphClass(), $key, null, null);
        }

        $resolver = $value['resolver'] ?? null;
        $type = $value['approver_type'] ?? $value['type'] ?? null;
        $id = $value['approver_id'] ?? $value['id'] ?? null;
        $config = $value['resolver_config'] ?? $value['config'] ?? null;

        if (is_string($resolver) && $resolver !== '') {
            if ($type !== null || $id !== null) {
                throw InvalidWorkflowDefinitionException::because('an approver entry cannot name both a model and a resolver.');
            }

            if (! class_exists($resolver) || ! is_subclass_of($resolver, ApproverResolver::class)) {
                throw InvalidWorkflowDefinitionException::because("resolver [{$resolver}] does not implement ApproverResolver.");
            }

            if ($config !== null && ! is_array($config)) {
                throw InvalidWorkflowDefinitionException::because('resolver config must be an array.');
            }

            $config = $config === null || $config === [] ? null : self::object($config);

            return new self(null, null, $resolver, $config);
        }

        if (! is_string($type) || $type === '' || ! is_int($id) && ! is_string($id)) {
            throw InvalidWorkflowDefinitionException::because('each approver must name a persisted model or a resolver.');
        }

        $class = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            throw InvalidWorkflowDefinitionException::because("approver type [{$type}] is not an Eloquent model.");
        }

        if (! (new $class)->newQuery()->whereKey($id)->exists()) {
            throw InvalidWorkflowDefinitionException::because("approver [{$type}:{$id}] does not exist.");
        }

        return new self($type, $id, null, null);
    }

    public function key(): string
    {
        if ($this->resolver !== null) {
            return 'resolver:'.$this->resolver.':'.json_encode($this->resolverConfig);
        }

        return 'model:'.$this->approverType.':'.$this->approverId;
    }

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return [
            'approver_type' => $this->approverType,
            'approver_id' => $this->approverId,
            'resolver' => $this->resolver,
            'resolver_config' => $this->resolverConfig,
        ];
    }

    /**
     * @param  array<mixed, mixed>  $value
     * @return array<string, mixed>
     */
    private static function object(array $value): array
    {
        $object = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw InvalidWorkflowDefinitionException::because('configuration arrays must use string keys.');
            }

            $object[$key] = $item;
        }

        return $object;
    }
}
