<?php

declare(strict_types=1);

namespace Workbench\App\Console;

use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use Illuminate\Console\Command as BaseCommand;
use Illuminate\Database\Eloquent\Model;
use Workbench\App\Models\Article;
use Workbench\App\Models\PurchaseOrder;
use Workbench\App\Models\User;

/**
 * What the demo's commands share: turning what somebody typed into the models
 * the package works with, and reporting when it cannot.
 *
 * The package ships no console commands for submitting or deciding, and no
 * screens either - approval is triggered from wherever the application already
 * decides things. These commands are the demo's stand-in for that application,
 * which is why they live under `Workbench\App` rather than in the package.
 */
abstract class Command extends BaseCommand
{
    /**
     * A person, by the local part of their seeded address ("grace"), their full
     * address, their name, or their key.
     */
    protected function person(?string $identifier): ?User
    {
        $identifier = is_string($identifier) ? trim($identifier) : '';

        if ($identifier === '') {
            $this->components->error('Say who is acting, with --as. Try --as=grace.');

            return null;
        }

        /** @var User|null $user */
        $user = User::query()
            ->where('email', $identifier)
            ->orWhere('email', $identifier.'@example.test')
            ->orWhere('name', $identifier)
            ->when(ctype_digit($identifier), fn ($query) => $query->orWhereKey((int) $identifier))
            ->first();

        if ($user === null) {
            $this->components->error("No such person: [{$identifier}].");
        }

        return $user;
    }

    /**
     * A request, by the public ULID it would be shown by or by its key.
     */
    protected function request(string $identifier): ?ApprovalRequest
    {
        /** @var ApprovalRequest|null $request */
        $request = ApprovalRequest::query()
            ->where('ulid', $identifier)
            ->when(ctype_digit($identifier), fn ($query) => $query->orWhereKey((int) $identifier))
            ->first();

        if ($request === null) {
            $this->components->error("No approval request found for [{$identifier}].");
        }

        return $request;
    }

    /**
     * A record under approval, by purchase order reference or article slug.
     */
    protected function approvable(string $identifier): ?Model
    {
        $record = PurchaseOrder::query()->where('reference', $identifier)->first()
            ?? Article::query()->where('slug', $identifier)->first();

        if ($record === null) {
            $this->components->error("No purchase order or article found for [{$identifier}].");
        }

        return $record;
    }

    /**
     * The value of a string option, or null when it was not given.
     */
    protected function text(string $option): ?string
    {
        $value = $this->option($option);

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    protected function label(?Model $model): string
    {
        if ($model === null) {
            return '-';
        }

        foreach (['name', 'title', 'reference'] as $attribute) {
            $value = $model->getAttributeValue($attribute);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $key = $model->getKey();

        return class_basename($model).' #'.(is_scalar($key) ? (string) $key : '?');
    }

    protected function reference(ApprovalRequest $request): string
    {
        $ulid = $request->getAttributeValue('ulid');

        return is_string($ulid) ? $ulid : '?';
    }

    /**
     * Values as they read in a diff. Draft values cross a JSON column, so what
     * comes back is scalars and arrays rather than the model's cast types.
     */
    protected function scalar(mixed $value): string
    {
        return match (true) {
            $value === null => '<fg=gray>null</>',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => json_encode($value, JSON_THROW_ON_ERROR),
        };
    }
}
