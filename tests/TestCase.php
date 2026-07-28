<?php

declare(strict_types=1);

namespace ByRcsc\LaravelApproval\Tests;

use ByRcsc\LaravelApproval\ApprovalServiceProvider;
use ByRcsc\LaravelApproval\Tests\Stubs\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [ApprovalServiceProvider::class];
    }

    /**
     * Config applied before the app boots. Set this and call
     * `refreshApplication()` to test anything decided at boot time - a plain
     * `config()->set()` lands too late, and the rebuild discards it.
     *
     * @var array<string, mixed>
     */
    protected array $bootConfig = [];

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            // SQLite ignores these unless asked, and the cascade rules are
            // half of what the schema tests are checking.
            'foreign_key_constraints' => true,
        ]);

        // The published default points at `App\Models\User`, which a Testbench
        // app does not have.
        $app['config']->set('approval.user_model', User::class);

        foreach ($this->bootConfig as $key => $value) {
            $app['config']->set($key, $value);
        }
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->createStubTables();

        $this->runApprovalMigration();
    }

    /**
     * Loading the real stub is the point: a suite that builds its own schema
     * proves nothing about the one that ships.
     */
    protected function runApprovalMigration(): void
    {
        $migration = require __DIR__.'/../database/migrations/create_approval_tables.php.stub';

        $migration->up();
    }

    /**
     * The host-app side of the polymorphic relations.
     */
    protected function createStubTables(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->boolean('published')->default(false);
            $table->timestamps();
        });
    }
}
