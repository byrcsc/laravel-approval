<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval;

use ByRcsc\LaravelApproval\Exceptions\InvalidConfigurationException;
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
            ->hasMigration('create_approval_tables');
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
}
