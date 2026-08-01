<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use ByRcsc\LaravelApproval\Enums\EscalationAction;
use ByRcsc\LaravelApproval\Events\ApprovalApproved;
use ByRcsc\LaravelApproval\Events\ApprovalRejected;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Support\EscalationStrategies;
use ByRcsc\LaravelApproval\Support\NotificationMap;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Approval\Conditions\AmountAtOrAboveStage;
use Workbench\App\Approval\Conditions\AmountAtOrAboveWorkflow;
use Workbench\App\Approval\Resolvers\TeamLeadResolver;
use Workbench\App\Approval\Resolvers\TeamMembersResolver;
use Workbench\App\Approval\Strategies\LogOverdueStrategy;
use Workbench\App\Console\AttachCommand;
use Workbench\App\Console\DecideCommand;
use Workbench\App\Console\DelegateCommand;
use Workbench\App\Console\EditCommand;
use Workbench\App\Console\InboxCommand;
use Workbench\App\Console\OverdueCommand;
use Workbench\App\Console\ResolveCommand;
use Workbench\App\Console\ShowCommand;
use Workbench\App\Console\SubmitCommand;
use Workbench\App\Models\Article;
use Workbench\App\Models\PurchaseOrder;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;
use Workbench\App\Notifications\ApprovalApprovedNotification;
use Workbench\App\Policies\ApprovalRequestPolicy;

use function Orchestra\Testbench\workbench_path;

/**
 * Configures the demo app.
 *
 * This stands in for the `config/approval.php`, `config/filesystems.php` and
 * `config/database.php` edits a real application would make when installing the
 * package. Keeping it in one file means the workbench carries no copies of the
 * framework's config files that would silently rot against future Laravel
 * releases, and it puts every integration point the package offers in one place
 * a reader can scan.
 *
 * Every seam is exercised somewhere below:
 *
 * | Seam                              | Where                                    |
 * |-----------------------------------|------------------------------------------|
 * | Which workflow a model uses       | `approval.workflows`, and the two         |
 * |                                   | `approvable_type` definitions             |
 * | Version-controlled workflows      | `approval.definitions`, run by            |
 * |                                   | `approval:sync`                           |
 * | Approver resolvers                | `TeamLeadResolver`, `TeamMembersResolver` |
 * | Workflow and stage conditions     | `AmountAtOrAbove*`                        |
 * | Guard mode                        | `approval.models`                         |
 * | Self-approval, per workflow       | the standard workflow's `settings`        |
 * | Escalation strategy replacement   | `approval.escalation.strategies`          |
 * | Notification replacement          | `approval.notifications.map`              |
 * | Policy extension                  | `Gate::policy()` below                    |
 * | Lifecycle events                  | the two listeners below                   |
 */
final class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->useDatabasePath(workbench_path('database'));

        config([
            // The demo needs a file-backed database so several terminals see
            // the same data. Testbench otherwise falls back to its in-memory
            // `testing` connection.
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => workbench_path('database', 'database.sqlite'),

            // Where `demo:attach` writes the files it puts in evidence. The
            // package never reads a disk - it records the location and the
            // checksum the application hands it - but a demo that attaches
            // something should attach something real.
            'filesystems.disks.evidence' => [
                'driver' => 'local',
                'root' => workbench_path('storage', 'app', 'evidence'),
                'throw' => true,
            ],
        ]);

        $this->configureApproval();
    }

    public function boot(): void
    {
        // Morph aliases, so `approvable_type` holds `purchase-order` rather than
        // a class name that a namespace change would invalidate. Enforcing the
        // map is the stricter choice: a model used polymorphically without an
        // entry fails loudly instead of writing a class name into the audit
        // trail. The package reads the map both ways round, so a workflow
        // definition may name either form.
        Relation::enforceMorphMap([
            'user' => User::class,
            'team' => Team::class,
            'purchase-order' => PurchaseOrder::class,
            'article' => Article::class,
        ]);

        // Takes precedence over Laravel's policy discovery, which would
        // otherwise find the package's own policy.
        Gate::policy(ApprovalRequest::class, ApprovalRequestPolicy::class);

        $this->registerLifecycleListeners();

        if ($this->app->runningInConsole()) {
            $this->commands([
                AttachCommand::class,
                DecideCommand::class,
                DelegateCommand::class,
                EditCommand::class,
                InboxCommand::class,
                OverdueCommand::class,
                ResolveCommand::class,
                ShowCommand::class,
                SubmitCommand::class,
            ]);
        }
    }

    /**
     * The application's own state machine, driven by the package's events.
     *
     * This is the division the package draws hardest: approving a request
     * changes the request's state and history and nothing about the record.
     * Publishing an article is this application's decision about its own model,
     * so it lives here rather than in the engine.
     *
     * A purchase order is the other case - its draft is applied by the package
     * because the draft is what was approved - so all the app does is move its
     * own `status` column, which no draft is allowed to touch.
     */
    private function registerLifecycleListeners(): void
    {
        Event::listen(ApprovalApproved::class, static function (ApprovalApproved $event): void {
            $approvable = $event->request->approvable;

            if ($approvable instanceof Article) {
                $approvable->forceFill(['published' => true])->save();
            }

            if ($approvable instanceof PurchaseOrder) {
                // Guard mode would refuse an ordinary save that touched a
                // drafted attribute. `status` is not one, and by this point the
                // request is closed anyway.
                $approvable->forceFill(['status' => 'ordered'])->save();
            }
        });

        Event::listen(ApprovalRejected::class, static function (ApprovalRejected $event): void {
            $approvable = $event->request->approvable;

            if ($approvable instanceof PurchaseOrder) {
                $approvable->forceFill(['status' => 'declined'])->save();
            }
        });
    }

    /**
     * Everything a real application would edit in `config/approval.php`.
     */
    private function configureApproval(): void
    {
        config([
            'approval.user_model' => User::class,

            // Where `submitForApproval()` looks third, after its own argument
            // and the model's `approvalWorkflow()`. The article workflow is
            // named here rather than on the model to keep both seams in play;
            // purchase orders use neither, and are matched by type instead.
            'approval.workflows' => [
                Article::class => 'article-review',
            ],

            // Guard mode: while a draft is pending, an Eloquent save touching a
            // drafted attribute throws. Query-builder updates and raw SQL do
            // not dispatch model events and stay outside this - the stale check
            // before the draft is applied is the backstop for those.
            'approval.models' => [
                PurchaseOrder::class => ['mode' => 'guard'],
            ],

            'approval.allow_self_approval' => false,

            'approval.definitions' => $this->workflowDefinitions(),

            'approval.notifications' => [
                // Off by default in the package, so installing it cannot send
                // anything. The demo turns it on; `MAIL_MAILER=log` in
                // `workbench/.env` keeps the mail inside the skeleton's log.
                'enabled' => true,
                'channels' => ['mail', 'database'],
                'map' => [
                    ...NotificationMap::defaults(),
                    // One entry replaces both the message and its audience.
                    ApprovalApproved::class => ApprovalApprovedNotification::class,
                ],
            ],

            'approval.escalation' => [
                'strategies' => [
                    ...EscalationStrategies::defaults(),
                    // Replacing a shipped strategy is a config entry, not a
                    // fork. The shipped `record_only` is deliberately empty;
                    // this one writes a line to the log.
                    EscalationAction::RecordOnly->value => LogOverdueStrategy::class,
                ],
            ],
        ]);
    }

    /**
     * The version-controlled purchase-order workflows.
     *
     * `approval:sync` creates and reconciles these. It applies additive and
     * supported configuration changes and refuses to remove or reorder an
     * existing stage or approver, so a definition file cannot quietly rewrite
     * the shape of a workflow that requests are already running under.
     *
     * Both are registered against the same model type, which is what makes them
     * a set for the engine to choose between: the executive one carries a
     * condition and the higher priority, so it wins when it applies and the
     * standard one runs when it does not.
     *
     * Neither names a concrete approver. That is deliberate - a definition in
     * version control that names user 4 is a definition that means something
     * different in every environment - so both reach people through resolvers.
     * The seeded `article-review` workflow shows the concrete form.
     *
     * @return array<string, array<string, mixed>>
     */
    private function workflowDefinitions(): array
    {
        return [

            'purchase-order-standard' => [
                'name' => 'Purchase order',
                'description' => 'Team lead, then any one of finance.',
                'approvable_type' => PurchaseOrder::class,
                'priority' => 0,
                'settings' => [
                    // Per-workflow override of `approval.allow_self_approval`.
                    // A team lead raising a small order of their own may sign
                    // their own first stage; the executive workflow below does
                    // not carry this, so it falls back to the global refusal.
                    'allow_self_approval' => true,
                ],
                'stages' => [
                    [
                        'name' => 'Team lead',
                        'required_approvals' => 1,
                        'sla_minutes' => 60,
                        'remind_every_minutes' => 30,
                        // Nobody is escalated until `approval:escalate` runs.
                        // When it does, this adds the finance team alongside
                        // the lead rather than replacing them: the lead may
                        // still be only slow, and discarding their assignment
                        // would throw away work in progress.
                        'escalation_strategy' => EscalationAction::Escalate->value,
                        'escalation_config' => [
                            'resolver' => TeamMembersResolver::class,
                            'resolver_config' => ['team' => 'finance'],
                        ],
                        'approvers' => [
                            [
                                'resolver' => TeamLeadResolver::class,
                                'resolver_config' => ['fallback_team' => 'finance'],
                            ],
                        ],
                    ],
                    [
                        'name' => 'Finance',
                        // 1-of-N: one entry resolves to the whole team, and one
                        // of them signing is enough.
                        'required_approvals' => 1,
                        'approvers' => [
                            [
                                'resolver' => TeamMembersResolver::class,
                                'resolver_config' => ['team' => 'finance', 'except_requester' => true],
                            ],
                        ],
                    ],
                ],
            ],

            'purchase-order-executive' => [
                'name' => 'Purchase order (executive)',
                'description' => 'Anything at or above 10,000, with a board stage above 50,000.',
                'approvable_type' => PurchaseOrder::class,
                'condition' => AmountAtOrAboveWorkflow::class,
                'condition_config' => ['amount' => 1_000_000],
                'priority' => 10,
                'stages' => [
                    [
                        'name' => 'Team lead',
                        'required_approvals' => 1,
                        'sla_minutes' => 60,
                        'approvers' => [
                            [
                                'resolver' => TeamLeadResolver::class,
                                'resolver_config' => ['fallback_team' => 'finance'],
                            ],
                        ],
                    ],
                    [
                        'name' => 'Finance',
                        // 2-of-N. Four eyes on anything this size.
                        'required_approvals' => 2,
                        // Any single rejection ends it, rather than waiting
                        // for the two approvals to become arithmetically
                        // impossible.
                        'rejection_policy' => 'any_rejection_vetoes',
                        'approvers' => [
                            [
                                'resolver' => TeamMembersResolver::class,
                                'resolver_config' => ['team' => 'finance', 'except_requester' => true],
                            ],
                        ],
                    ],
                    [
                        'name' => 'Board sign-off',
                        // Null - omitted - means every resolved approver must
                        // sign. Unanimous, whoever the executive team turns out
                        // to hold at submission.
                        'required_approvals' => null,
                        'condition' => AmountAtOrAboveStage::class,
                        'condition_config' => ['amount' => 5_000_000],
                        'sla_minutes' => 2_880,
                        'approvers' => [
                            [
                                'resolver' => TeamMembersResolver::class,
                                'resolver_config' => ['team' => 'executive'],
                            ],
                        ],
                    ],
                ],
            ],

        ];
    }
}
