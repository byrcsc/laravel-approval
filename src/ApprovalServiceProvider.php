<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval;

use ByRcsc\LaravelApproval\Commands\ApplyDelegationsCommand;
use ByRcsc\LaravelApproval\Commands\DoctorCommand;
use ByRcsc\LaravelApproval\Commands\EscalateOverdueCommand;
use ByRcsc\LaravelApproval\Commands\ResyncRequestCommand;
use ByRcsc\LaravelApproval\Commands\StatusCommand;
use ByRcsc\LaravelApproval\Commands\SyncCommand;
use ByRcsc\LaravelApproval\Commands\VerifyCommand;
use ByRcsc\LaravelApproval\Contracts\EscalationStrategy;
use ByRcsc\LaravelApproval\Enums\ApprovalMode;
use ByRcsc\LaravelApproval\Enums\EscalationAction;
use ByRcsc\LaravelApproval\Exceptions\InvalidConfigurationException;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Listeners\SendApprovalNotification;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Support\NotificationMap;
use ByRcsc\LaravelApproval\Support\Payload;
use ByRcsc\LaravelApproval\Support\TableNames;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Compilers\BladeCompiler;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class ApprovalServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-approval')
            ->hasConfigFile('approval')
            ->hasTranslations()
            ->hasViews()
            ->hasMigrations(['create_approval_tables', 'create_approval_delegations_table'])
            ->hasCommands([
                ApplyDelegationsCommand::class,
                EscalateOverdueCommand::class,
                DoctorCommand::class,
                ResyncRequestCommand::class,
                StatusCommand::class,
                SyncCommand::class,
                VerifyCommand::class,
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
        $this->validateKeyType('user_key_type', Payload::string($config, 'user_key_type'));
        $this->validateKeyType('approvable_key_type', Payload::string($config, 'approvable_key_type', 'int'));
        ApprovalModels::validate(Payload::object($config, 'model_classes') ?? []);
        $this->validateModelModes(Payload::object($config, 'models') ?? []);
        $this->validateEscalationStrategies(Payload::object($config, 'escalation') ?? []);

        NotificationMap::validate(Payload::object($config, 'notifications') ?? []);

        $this->registerNotificationListeners();
        $this->registerBladeDirectives();
        $this->registerAboutSection();
    }

    /**
     * `@pendingApproval($model)` and `@canApprove($requestOrModel)`, which
     * checks the signed-in user unless another one is passed. Both ask the same
     * questions as the facade.
     */
    private function registerBladeDirectives(): void
    {
        $this->callAfterResolving('blade.compiler', function (BladeCompiler $blade): void {
            $blade->if('pendingApproval', fn (Model $approvable): bool => Approval::pendingFor($approvable) !== null);

            $blade->if('canApprove', function (Model $subject, ?Model $user = null): bool {
                $user ??= Auth::user();
                $request = $subject instanceof ApprovalRequest ? $subject : Approval::pendingFor($subject);

                return $user instanceof Model && $request !== null && Approval::canApprove($request, $user);
            });
        });
    }

    /**
     * Settings that change engine behavior, for `php artisan about`. Read from
     * config only, so the command works before the tables exist.
     */
    private function registerAboutSection(): void
    {
        if (! class_exists(AboutCommand::class)) {
            return;
        }

        AboutCommand::add('Laravel Approval', function (): array {
            $config = Payload::asObject(config('approval')) ?? [];

            return [
                'Notifications' => NotificationMap::enabled() ? '<fg=green;options=bold>ENABLED</>' : 'OFF',
                'Self-approval' => Payload::bool($config, 'allow_self_approval') ? '<fg=yellow;options=bold>ALLOWED</>' : 'BLOCKED',
                'User key type' => Payload::string($config, 'user_key_type', 'int'),
                'Approvable key type' => Payload::string($config, 'approvable_key_type', 'int'),
                'Custom models' => implode(', ', array_keys(ApprovalModels::overridden())) ?: 'NONE',
            ];
        });
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
     * An absent key falls back to its default, so a config published before a
     * table existed keeps booting. A key that is present must name a table.
     *
     * @param  array<string, mixed>  $tableNames
     */
    private function validateTableNames(array $tableNames): void
    {
        foreach (TableNames::keys() as $key) {
            if (! array_key_exists($key, $tableNames)) {
                continue;
            }

            $name = $tableNames[$key];

            if (! is_string($name) || trim($name) === '') {
                throw InvalidConfigurationException::blankTableName($key);
            }
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

    private function validateKeyType(string $key, string $type): void
    {
        if (! in_array($type, ['int', 'uuid', 'ulid', 'string'], true)) {
            throw InvalidConfigurationException::invalidKeyType($key, $type);
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
