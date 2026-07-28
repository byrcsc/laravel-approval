<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Exceptions;

/**
 * Thrown while booting rather than on first use: a config mistake that surfaces
 * the day of the first approval is a mistake that reached production.
 */
final class InvalidConfigurationException extends ApprovalException
{
    /**
     * @param  list<string>  $missing
     */
    public static function missingTableNames(array $missing): self
    {
        return new self(sprintf(
            'The [approval.table_names] config is missing an entry for: %s. Re-publish the package config with `php artisan vendor:publish --tag="laravel-approval-config"`.',
            implode(', ', $missing),
        ));
    }

    public static function blankTableName(string $key): self
    {
        return new self("The [approval.table_names.{$key}] config must be a non-empty table name.");
    }

    public static function unknownUserModel(string $model): self
    {
        return new self("The [approval.user_model] config points at [{$model}], which does not exist.");
    }

    public static function userModelIsNotAModel(string $model): self
    {
        return new self("The [approval.user_model] config points at [{$model}], which is not an Eloquent model.");
    }
}
