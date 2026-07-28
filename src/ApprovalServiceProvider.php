<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval;

use ByRcsc\LaravelApproval\Commands\DoctorCommand;
use ByRcsc\LaravelApproval\Commands\EscalateOverdueCommand;
use ByRcsc\LaravelApproval\Commands\ResyncRequestCommand;
use ByRcsc\LaravelApproval\Commands\StatusCommand;
use ByRcsc\LaravelApproval\Contracts\EscalationStrategy;
use ByRcsc\LaravelApproval\Enums\ApprovalMode;
use ByRcsc\LaravelApproval\Enums\EscalationAction;
use ByRcsc\LaravelApproval\Exceptions\InvalidConfigurationException;
use ByRcsc\LaravelApproval\Listeners\SendApprovalNotification;
use ByRcsc\LaravelApproval\Support\NotificationMap;
use ByRcsc\LaravelApproval\Support\Payload;
use ByRcsc\LaravelApproval\Support\TableNames;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class ApprovalServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-approval')
            ->hasConfigFile('approval')
            ->hasMigration('create_approval_tables')
            ->hasCommands([
                EscalateOverdueCommand::class,
                DoctorCommand::class,
                ResyncRequestCommand::class,
                StatusCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(ApprovalManager::class);
        $this->app->alias(ApprovalManager::class, 'approval');
    }

    public function packageBooted(): void
    {
        /** @var array<string, mixed> $config */
        $config = Payload::asObject($this->app->make('config')->get('approval')) ?? [];

        $this->validateTableNames(Payload::object($config, 'table_names') ?? []);
        $this->validateUserModel(Payload::string($config, 'user_model'));
        $this->validateModelModes(Payload::object($config, 'models') ?? []);
        $this->validateEscalationStrategies(Payload::object($config, 'escalation') ?? []);

        NotificationMap::validate(Payload::object($config, 'notifications') ?? []);

        $this->registerNotificationListeners();
    }

    /**
     * Only the mapped events, and only when notifications are on - an
     * application that leaves them off registers no listeners at all.
     */
    private function registerNotificationListeners(): void
    {
        $events = $this->app->make('events');

        foreach (array_keys(NotificationMap::resolved()) as $event) {
            $events->listen($event, SendApprovalNotification::class);
        }
    }

    /**
     * Entries that are present must be usable; entries that are absent fall
     * back to the packaged map, so a config published before a case existed
     * keeps working rather than failing on a key its author never saw.
     *
     * @param  array<string, mixed>  $escalation
     */
    private function validateEscalationStrategies(array $escalation): void
    {
        foreach (Payload::object($escalation, 'strategies') ?? [] as $case => $strategy) {
            if (EscalationAction::tryFrom($case) === null) {
                throw InvalidConfigurationException::unknownEscalationCase($case);
            }

            if (! is_string($strategy) || ! class_exists($strategy)) {
                throw InvalidConfigurationException::unknownEscalationStrategy($case);
            }

            if (! is_subclass_of($strategy, EscalationStrategy::class)) {
                throw InvalidConfigurationException::escalationStrategyIsNotAStrategy($case, $strategy);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $tableNames
     */
    private function validateTableNames(array $tableNames): void
    {
        $missing = [];

        foreach (TableNames::keys() as $key) {
            if (! array_key_exists($key, $tableNames)) {
                $missing[] = $key;

                continue;
            }

            $name = $tableNames[$key];

            if (! is_string($name) || trim($name) === '') {
                throw InvalidConfigurationException::blankTableName($key);
            }
        }

        if ($missing !== []) {
            throw InvalidConfigurationException::missingTableNames($missing);
        }
    }

    /**
     * A configured model that is not a model is always a mistake, so it is
     * fatal. One that cannot be autoloaded is not: the default points at
     * `App\Models\User`, which plenty of applications do not have, and the
     * value stays inert until something asks for it.
     */
    private function validateUserModel(string $userModel): void
    {
        if (trim($userModel) === '') {
            throw InvalidConfigurationException::unknownUserModel($userModel);
        }

        if (class_exists($userModel) && ! is_subclass_of($userModel, Model::class)) {
            throw InvalidConfigurationException::userModelIsNotAModel($userModel);
        }
    }

    /**
     * @param  array<string, mixed>  $models
     */
    private function validateModelModes(array $models): void
    {
        foreach ($models as $model => $settings) {
            $mode = is_array($settings) ? ($settings['mode'] ?? ApprovalMode::Explicit->value) : null;

            if (! is_string($mode) || ApprovalMode::tryFrom($mode) === null) {
                throw InvalidConfigurationException::invalidModelMode($model, $mode);
            }
        }
    }
}
