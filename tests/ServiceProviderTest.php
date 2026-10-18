<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\ApprovalManager;
use ByRcsc\LaravelApproval\Exceptions\InvalidConfigurationException;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Models\ApprovalDelegation;
use ByRcsc\LaravelApproval\Tests\Stubs\CustomApprovalRequest;
use ByRcsc\LaravelApproval\Tests\Stubs\Post;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
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

it('boots with a table key the published config predates, using its default name', function () {
    $names = config('approval.table_names');
    unset($names['delegations']);

    $this->bootConfig = ['approval.table_names' => $names];
    $this->refreshApplication();

    expect((new ApprovalDelegation)->getTable())->toBe('approval_delegations');
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

it('answers the pending and can-approve questions in Blade', function () {
    $approver = user('Approver');
    $post = post();
    $render = fn (Model $user): string => (string) preg_replace('/\s+/', ' ', trim(Blade::render(
        '@pendingApproval($post) pending @endpendingApproval @canApprove($post, $user) can @else cannot @endcanApprove',
        ['post' => $post, 'user' => $user],
    )));

    expect($render($approver))->toBe('cannot');

    $post->submitForApproval(workflow([['approvers' => [$approver]]]), null, user());

    expect($render($approver))->toBe('pending can')
        ->and($render(user('Stranger')))->toBe('pending cannot');
});

it('reports engine settings in php artisan about', function () {
    config()->set('approval.model_classes.requests', CustomApprovalRequest::class);

    $this->artisan('about', ['--only' => 'laravel approval'])
        ->expectsOutputToContain('Self-approval')
        ->expectsOutputToContain('requests')
        ->assertSuccessful();
});
