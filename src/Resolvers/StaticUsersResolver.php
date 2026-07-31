<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Resolvers;

use ByRcsc\LaravelApproval\Contracts\ApproverResolver;
use ByRcsc\LaravelApproval\Data\StageSnapshot;
use ByRcsc\LaravelApproval\Support\Payload;
use Illuminate\Database\Eloquent\Model;

/**
 * The plainest resolver there is: a list of primary keys.
 *
 *     'resolver' => StaticUsersResolver::class,
 *     'resolver_config' => ['ids' => [4, 11], 'model' => Team::class],
 *
 * `model` is optional and defaults to `approval.user_model`. It exists mostly
 * so an admin UI has one resolver that can point at any table, but a stage can
 * just as well hold concrete approver rows - use this when the same list is
 * shared by several stages and you would rather edit it in one place.
 */
final class StaticUsersResolver implements ApproverResolver
{
    /**
     * @param  array<string, mixed>  $config
     * @return iterable<int, Model>
     */
    public function resolve(StageSnapshot $stage, Model $approvable, Model $requester, array $config): iterable
    {
        $ids = array_values(array_filter(
            Payload::array($config, 'ids'),
            static fn (mixed $id): bool => is_int($id) || (is_string($id) && $id !== ''),
        ));

        if ($ids === []) {
            return [];
        }

        $model = $this->model($config);

        if ($model === null) {
            return [];
        }

        /** @var list<Model> $approvers */
        $approvers = $model->newQuery()->findMany($ids)->all();

        return $approvers;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function model(array $config): ?Model
    {
        $class = Payload::classString($config, 'model');

        if ($class === null) {
            /** @var mixed $configured */
            $configured = config('approval.user_model');
            $class = is_string($configured) && class_exists($configured) ? $configured : null;
        }

        if ($class === null || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        return new $class;
    }
}
