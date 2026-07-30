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
            $before = $this->rawValues($this->fresh($approvable) ?? $approvable, $drafted);

            $result = ApprovalGuard::without($approvable, fn (): mixed => $callback($approvable));

            // Read back from the database rather than refreshing the instance in
            // hand: the callback may have deleted the record, and that outcome
            // still has to reach the audit history.
            $fresh = $this->fresh($approvable);

            ($this->recordAction)(
                request: $request,
                action: ActionType::GuardBypassed,
                actor: $actor,
                comment: $comment,
                metadata: $fresh === null
                    ? ['attributes' => $drafted, 'deleted' => true]
                    : ['attributes' => $this->changed($drafted, $before, $this->rawValues($fresh, $drafted))],
            );

            return $result;
        });
    }

    /**
     * The record as the database holds it, or null once it no longer holds it
     * at all. A soft delete reads as gone here, which is the right answer: the
     * bypass took the record out of play either way.
     */
    private function fresh(Model $model): ?Model
    {
        return $model->newQuery()->whereKey($model->getKey())->first();
    }

    /**
     * @param  list<string>  $attributes
     * @return array<string, mixed>
     */
    private function rawValues(Model $model, array $attributes): array
    {
        $values = [];

        foreach ($attributes as $attribute) {
            $values[$attribute] = $model->getRawOriginal($attribute);
        }

        return $values;
    }

    /**
     * @param  list<string>  $attributes
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<string>
     */
    private function changed(array $attributes, array $before, array $after): array
    {
        $changed = [];

        foreach ($attributes as $attribute) {
            if (($before[$attribute] ?? null) !== ($after[$attribute] ?? null)) {
                $changed[] = $attribute;
            }
        }

        return $changed;
    }
}
