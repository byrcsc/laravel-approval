<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Actions;

use ByRcsc\LaravelApproval\Enums\ActionType;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Support\ApprovalGuard;
use ByRcsc\LaravelApproval\Support\Attributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class BypassApprovalGuard
{
    public function __construct(private readonly RecordAction $recordAction) {}

    /**
     * @template TValue
     *
     * @param  callable(Model): TValue  $callback
     * @return TValue
     */
    public function __invoke(
        Model $approvable,
        ApprovalRequest $request,
        callable $callback,
        ?Model $actor = null,
        ?string $comment = null,
    ): mixed {
        return DB::transaction(function () use ($approvable, $request, $callback, $actor, $comment): mixed {
            $drafted = array_keys(Attributes::object($request, 'new_attributes'));
            $before = $this->rawValues($approvable, $drafted);

            $result = ApprovalGuard::without($approvable, fn (): mixed => $callback($approvable));

            // Read back from the database rather than refreshing: the callback
            // may have deleted the record, and a bypass that removed it is
            // still a bypass worth its audit line.
            $after = $this->rawValues($approvable, $drafted);
            $changed = [];

            foreach ($drafted as $attribute) {
                if (($before[$attribute] ?? null) !== ($after[$attribute] ?? null)) {
                    $changed[] = $attribute;
                }
            }

            ($this->recordAction)(
                request: $request,
                action: ActionType::GuardBypassed,
                actor: $actor,
                comment: $comment,
                metadata: ['attributes' => $changed],
            );

            return $result;
        });
    }

    /**
     * @param  list<string>  $attributes
     * @return array<string, mixed>
     */
    private function rawValues(Model $model, array $attributes): array
    {
        $fresh = $model->newQuery()->whereKey($model->getKey())->first() ?? $model;
        $values = [];

        foreach ($attributes as $attribute) {
            $values[$attribute] = $fresh->getRawOriginal($attribute);
        }

        return $values;
    }
}
