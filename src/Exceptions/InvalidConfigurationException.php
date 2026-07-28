<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Exceptions;

use ByRcsc\LaravelApproval\Contracts\EscalationStrategy;
use ByRcsc\LaravelApproval\Enums\EscalationAction;
use ByRcsc\LaravelApproval\Notifications\ApprovalNotification;

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

    public static function unknownNotificationEvent(string $event): self
    {
        return new self("The [approval.notifications.map] config is keyed by [{$event}], which is not a class that exists.");
    }

    public static function unknownNotification(string $event): self
    {
        return new self("The [approval.notifications.map] entry for [{$event}] must be null or the name of a class that exists.");
    }

    public static function notificationIsNotANotification(string $event, string $notification): self
    {
        return new self(sprintf(
            'The [approval.notifications.map] entry for [%s] points at [%s], which does not extend %s.',
            $event,
            $notification,
            ApprovalNotification::class,
        ));
    }

    public static function unknownEscalationCase(string $case): self
    {
        return new self(sprintf(
            'The [approval.escalation.strategies] config has an entry for [%s], which is not an escalation case. Expected one of: %s.',
            $case,
            implode(', ', array_column(EscalationAction::cases(), 'value')),
        ));
    }

    public static function unknownEscalationStrategy(string $case): self
    {
        return new self("The [approval.escalation.strategies.{$case}] config must be the name of a class that exists.");
    }

    public static function escalationStrategyIsNotAStrategy(string $case, string $strategy): self
    {
        return new self(sprintf(
            'The [approval.escalation.strategies.%s] config points at [%s], which does not implement %s.',
            $case,
            $strategy,
            EscalationStrategy::class,
        ));
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
