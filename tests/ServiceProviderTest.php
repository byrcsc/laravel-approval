<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\ApprovalManager;
use ByRcsc\LaravelApproval\Exceptions\InvalidConfigurationException;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Tests\Stubs\Post;
use Illuminate\Support\ServiceProvider;

it('resolves the manager as a singleton behind the facade', function () {
    expect(app(ApprovalManager::class))->toBeInstanceOf(ApprovalManager::class)
        ->and(app('approval'))->toBe(app(ApprovalManager::class))
        ->and(Approval::getFacadeRoot())->toBe(app(ApprovalManager::class));
});

it('publishes a config file that holds every table name', function () {
    expect(config('approval.table_names'))
        ->toHaveKeys(['workflows', 'workflow_stages', 'workflow_stage_approvers', 'requests', 'request_stages', 'assignments', 'actions']);
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

it('refuses an unknown per-model approval mode at boot', function () {
    $this->bootConfig = ['approval.models' => [Post::class => ['mode' => 'strict-ish']]];

    expect(fn () => $this->refreshApplication())
        ->toThrow(InvalidConfigurationException::class, 'Expected one of: explicit, guard');
});

it('documents every publish tag it registers', function () {
    $readme = (string) file_get_contents(dirname(__DIR__).'/README.md');

    $tags = array_filter(
        array_keys(ServiceProvider::$publishGroups),
        fn (string $tag): bool => str_starts_with($tag, 'approval-'),
    );

    expect($tags)->not->toBeEmpty();

    foreach ($tags as $tag) {
        expect($readme)->toContain("--tag=\"{$tag}\"");
    }

    expect($readme)->not->toContain('--tag="laravel-approval-');
});
