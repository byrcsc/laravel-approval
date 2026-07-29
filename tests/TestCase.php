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

    /**
     * Rebuild the application with extra config, and put the schema back.
     *
     * `refreshApplication()` alone hands back an empty database: the SQLite
     * connection is in memory, so rebuilding the container throws the tables
     * away with it. Anything that boots differently *and* touches the database
     * wants this rather than the two calls by hand.
     *
     * @param  array<string, mixed>  $config
     */
    public function rebootWith(array $config): void
    {
        $this->bootConfig = array_merge($this->bootConfig, $config);

        $this->refreshApplication();

        $this->defineDatabaseMigrations();
    }

    protected function defineEnvironment($app): void
    {
        // The `encrypted` cast needs one, and the draft stale-check has to work
        // against encrypted attributes.
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $this->databaseConnection());

        // The published default points at `App\Models\User`, which a Testbench
        // app does not have.
        $app['config']->set('approval.user_model', User::class);

        foreach ($this->bootConfig as $key => $value) {
            $app['config']->set($key, $value);
        }
    }

    /**
     * Which engine the suite runs against. SQLite in memory is the default;
     * CI's database matrix sets `DB_DRIVER` to prove the engine against the
     * things SQLite will not tell the truth about - `lockForUpdate()`,
     * cascade behavior, and index use.
     *
     * @return array<string, mixed>
     */
    protected function databaseConnection(): array
    {
        return match (env('DB_DRIVER', 'sqlite')) {
            'mysql' => [
                'driver' => 'mysql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => (int) env('DB_PORT', 3306),
                'database' => env('DB_DATABASE', 'approval_test'),
                'username' => env('DB_USERNAME', 'root'),
                'password' => env('DB_PASSWORD', ''),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
            ],
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => (int) env('DB_PORT', 5432),
                'database' => env('DB_DATABASE', 'approval_test'),
                'username' => env('DB_USERNAME', 'postgres'),
                'password' => env('DB_PASSWORD', 'postgres'),
                'charset' => 'utf8',
                'prefix' => '',
            ],
            default => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                // SQLite ignores these unless asked, and the cascade rules are
                // half of what the schema tests are checking.
                'foreign_key_constraints' => true,
            ],
        };
    }

    protected function defineDatabaseMigrations(): void
    {
        // A memory database is born empty; a server database has to be put
        // back to that state before each test builds its schema.
        if (env('DB_DRIVER', 'sqlite') !== 'sqlite') {
            Schema::dropAllTables();
        }

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

        Schema::create('uuid_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('ulid_users', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->boolean('published')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference');
            $table->unsignedInteger('amount')->default(0);
            $table->string('status')->default('draft');
            $table->timestamps();
        });

        // Every cast the draft stale-check has to survive, on one table.
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('reference');
            $table->date('issued_on')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('status')->default('draft');
            $table->text('account_number')->nullable();
            $table->decimal('total', 12, 2)->default(0);
            $table->float('rate')->default(0);
            $table->integer('quantity')->default(0);
            $table->boolean('settled')->default(false);
            $table->json('lines')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }
}
