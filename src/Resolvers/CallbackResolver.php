<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Resolvers;

use ByRcsc\LaravelApproval\Contracts\ApproverResolver;
use ByRcsc\LaravelApproval\Data\LevelSnapshot;
use ByRcsc\LaravelApproval\Support\Payload;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;

/**
 * Delegates to a class the host app already has, so an existing
 * `ManagerLookup::forEmployee()` becomes an approver rule without being
 * rewritten around this package's interface.
 *
 *     'resolver' => CallbackResolver::class,
 *     'resolver_config' => ['callback' => 'App\Org\ManagerLookup@forEmployee'],
 *
 * The class comes out of the container, so its own dependencies are injected.
 * The method receives the same four arguments an {@see ApproverResolver} does.
 *
 * Reach for this when the logic already exists somewhere. When it does not,
 * write the resolver: an implementation of the interface is about fifteen lines
 * and says what it is in its own name.
 */
final class CallbackResolver implements ApproverResolver
{
    public function __construct(private readonly Container $container) {}

    /**
     * @param  array<string, mixed>  $config
     * @return iterable<int, Model>
     */
    public function resolve(LevelSnapshot $level, Model $approvable, Model $submitter, array $config): iterable
    {
        $callback = Payload::nullableString($config, 'callback');

        if ($callback === null || $callback === '') {
            return [];
        }

        [$class, $method] = str_contains($callback, '@')
            ? explode('@', $callback, 2)
            : [$callback, '__invoke'];

        if (! class_exists($class)) {
            return [];
        }

        $instance = $this->container->make($class);

        if (! is_object($instance) || ! method_exists($instance, $method)) {
            return [];
        }

        /** @var mixed $approvers */
        $approvers = $instance->{$method}($level, $approvable, $submitter, $config);

        return is_iterable($approvers) ? $this->onlyModels($approvers) : [];
    }

    /**
     * @param  iterable<mixed>  $approvers
     * @return list<Model>
     */
    private function onlyModels(iterable $approvers): array
    {
        $models = [];

        foreach ($approvers as $approver) {
            if ($approver instanceof Model) {
                $models[] = $approver;
            }
        }

        return $models;
    }
}
