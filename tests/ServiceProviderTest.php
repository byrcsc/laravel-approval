<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\ApprovalManager;
use ByRcsc\LaravelApproval\Exceptions\InvalidConfigurationException;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Tests\Stubs\Post;

it('resolves the manager as a singleton behind the facade', function () {
    expect(app(ApprovalManager::class))->toBeInstanceOf(ApprovalManager::class)
        ->and(app('approval'))->toBe(app(ApprovalManager::class))
        ->and(Approval::getFacadeRoot())->toBe(app(ApprovalManager::class));
});

it('publishes a config file that holds every table name', function () {
    expect(config('approval.table_names'))
        ->toHaveKeys(['workflows', 'levels', 'level_approvers', 'requests', 'steps', 'step_approvers', 'actions']);
});

it('refuses to boot when a table name is missing', function () {
    $this->bootConfig = ['approval.table_names' => ['workflows' => 'approval_workflows']];

    expect(fn () => $this->refreshApplication())
        ->toThrow(InvalidConfigurationException::class, 'missing an entry for');
});

it('refuses to boot when a table name is blank', function () {
    $names = config('approval.table_names');
    $names['requests'] = '  ';

    $this->bootConfig = ['approval.table_names' => $names];

    expect(fn () => $this->refreshApplication())
        ->toThrow(InvalidConfigurationException::class, 'approval.table_names.requests');
});

it('refuses to boot when the user model is not a model', function () {
    $this->bootConfig = ['approval.user_model' => ApprovalManager::class];

    expect(fn () => $this->refreshApplication())
        ->toThrow(InvalidConfigurationException::class, 'not an Eloquent model');
});

it('refuses to boot without a user model at all', function () {
    $this->bootConfig = ['approval.user_model' => ''];

    expect(fn () => $this->refreshApplication())
        ->toThrow(InvalidConfigurationException::class);
});

it('boots with a user model it cannot autoload yet', function () {
    // Refusing to boot over the published default would break installs rather
    // than catch mistakes - plenty of applications have no `App\Models\User`.
    $this->bootConfig = ['approval.user_model' => 'App\\Models\\User'];

    $this->refreshApplication();

    expect(config('approval.user_model'))->toBe('App\\Models\\User');
});

it('accepts any Eloquent model as the user model', function () {
    $this->bootConfig = ['approval.user_model' => Post::class];

    $this->refreshApplication();

    expect(config('approval.user_model'))->toBe(Post::class);
});
