<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use ByRcsc\LaravelApproval\Enums\EscalationAction;
use ByRcsc\LaravelApproval\Enums\RejectionPolicy;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflow;
use ByRcsc\LaravelApproval\Models\ApprovalWorkflowStage;
use ByRcsc\LaravelApproval\Resolvers\StaticUsersResolver;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Workbench\App\Models\Article;
use Workbench\App\Models\PurchaseOrder;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/**
 * The demo org chart, its records, and the one workflow that is built here
 * rather than synced from config.
 *
 * Idempotent throughout, so it can be re-run over an already-populated demo
 * database. Testbench runs it off the `DatabaseRefreshed` event, which means
 * `migrate:fresh` seeds too.
 *
 * Order matters in one place: the `article-review` workflow is created before
 * any article, because creating an article submits it and a submission needs
 * its workflow to exist.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $people = $this->people();
        $this->teams($people);
        $this->articleWorkflow($people);
        $this->purchaseOrders();
        $this->articles($people);
    }

    /**
     * @return array<string, User>
     */
    private function people(): array
    {
        $names = [
            'ada' => 'Ada Lovelace',
            'linus' => 'Linus Torvalds',
            'grace' => 'Grace Hopper',
            'alan' => 'Alan Turing',
            'katherine' => 'Katherine Johnson',
            'edsger' => 'Edsger Dijkstra',
            'barbara' => 'Barbara Liskov',
            'donald' => 'Donald Knuth',
            'radia' => 'Radia Perlman',
            'margaret' => 'Margaret Hamilton',
            'karen' => 'Karen Sparck Jones',
        ];

        $people = [];

        foreach ($names as $handle => $name) {
            /** @var User $user */
            $user = User::query()->firstOrCreate(
                ['email' => "{$handle}@example.test"],
                ['name' => $name, 'password' => Hash::make('password')],
            );

            $people[$handle] = $user;
        }

        return $people;
    }

    /**
     * The org chart the approver resolvers read. A team is a container model:
     * naming one as an approver would assign the team record itself, so these
     * exist to be turned into people by `TeamLeadResolver` and
     * `TeamMembersResolver`.
     *
     * @param  array<string, User>  $people
     */
    private function teams(array $people): void
    {
        $structure = [
            'procurement' => ['name' => 'Procurement', 'lead' => 'ada', 'members' => ['ada', 'linus']],
            'finance' => ['name' => 'Finance', 'lead' => 'grace', 'members' => ['grace', 'alan', 'katherine']],
            'executive' => ['name' => 'Executive', 'lead' => 'edsger', 'members' => ['edsger', 'barbara']],
            'editorial' => ['name' => 'Editorial', 'lead' => 'donald', 'members' => ['donald', 'radia']],
            'legal' => ['name' => 'Legal', 'lead' => 'margaret', 'members' => ['margaret']],
            'audit' => ['name' => 'Audit', 'lead' => null, 'members' => ['karen']],
        ];

        foreach ($structure as $slug => $team) {
            /** @var Team $record */
            $record = Team::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $team['name'],
                    'lead_id' => $team['lead'] === null ? null : $people[$team['lead']]->getKey(),
                ],
            );

            $record->members()->syncWithoutDetaching(
                array_map(static fn (string $handle): mixed => $people[$handle]->getKey(), $team['members']),
            );
        }
    }

    /**
     * The article workflow, written as ordinary Eloquent models.
     *
     * The purchase-order workflows are version-controlled definitions synced by
     * `approval:sync`; this one is built by hand so both paths are shown. A real
     * application would more often build it from an administration screen, which
     * is why the package leaves these as plain models rather than hiding them
     * behind a builder.
     *
     * `approvable_type` is left null on purpose: this workflow is reached by
     * name, through the `approval.workflows` map, rather than by being
     * registered against a type.
     *
     * @param  array<string, User>  $people
     */
    private function articleWorkflow(array $people): void
    {
        /** @var ApprovalWorkflow $workflow */
        $workflow = ApprovalWorkflow::query()->updateOrCreate(
            ['slug' => 'article-review'],
            [
                'name' => 'Article review',
                'description' => 'One editor, then a legal hold that lapses on its own.',
                'active' => true,
            ],
        );

        if ($workflow->stages()->exists()) {
            return;
        }

        /** @var ApprovalWorkflowStage $editorial */
        $editorial = $workflow->stages()->create([
            'sequence' => 1,
            'name' => 'Editorial review',
            // 1-of-2: either editor is enough.
            'required_approvals' => 1,
            'rejection_policy' => RejectionPolicy::AnyRejectionVetoes,
        ]);

        // Concrete approver rows: each names one exact actor. This is the form
        // a definition in version control should avoid - user ids mean
        // different people in different environments - and the right form for
        // rows an administration screen writes.
        foreach (['donald', 'radia'] as $handle) {
            $editorial->approvers()->create([
                'approver_type' => $people[$handle]->getMorphClass(),
                'approver_id' => $people[$handle]->getKey(),
            ]);
        }

        /** @var ApprovalWorkflowStage $legal */
        $legal = $workflow->stages()->create([
            'sequence' => 2,
            'name' => 'Legal hold',
            'required_approvals' => 1,
            'sla_minutes' => 15,
            // The fourth escalation case: no objection inside the window is
            // treated as no objection. Nothing happens until `approval:escalate`
            // runs, and `demo:overdue` is how the demo reaches the deadline
            // without waiting a quarter of an hour.
            'escalation_strategy' => EscalationAction::AutoApprove,
            'escalation_config' => ['comment' => 'Legal raised no objection inside the hold.'],
        ]);

        // A shipped resolver, pointed at a list of keys. Worth using when the
        // same list is shared by several stages and you would rather edit it in
        // one place; a resolver of your own is worth writing when the list is
        // really a rule.
        $legal->approvers()->create([
            'resolver' => StaticUsersResolver::class,
            'resolver_config' => ['ids' => [$people['margaret']->getKey()]],
        ]);
    }

    /**
     * Purchase orders are left unsubmitted: submitting one is the first step of
     * the demo loop, and which workflow it lands in depends on the amount that
     * `demo:submit` proposes rather than the amount seeded here.
     */
    private function purchaseOrders(): void
    {
        $procurement = Team::query()->where('slug', 'procurement')->firstOrFail();

        $orders = [
            ['reference' => 'PO-1001', 'vendor' => 'Northwind Supplies', 'amount' => 45_000],
            ['reference' => 'PO-1002', 'vendor' => 'Contoso Hardware', 'amount' => 780_000],
            ['reference' => 'PO-1003', 'vendor' => 'Fabrikam Logistics', 'amount' => 6_400_000],
        ];

        foreach ($orders as $order) {
            PurchaseOrder::query()->firstOrCreate(
                ['reference' => $order['reference']],
                [
                    'vendor' => $order['vendor'],
                    'amount' => $order['amount'],
                    'currency' => 'EUR',
                    'status' => 'draft',
                    'team_id' => $procurement->getKey(),
                ],
            );
        }
    }

    /**
     * Creating these submits them, so a freshly built demo already has two
     * requests waiting on the editors.
     *
     * @param  array<string, User>  $people
     */
    private function articles(array $people): void
    {
        $drafts = [
            'A Note On Two Problems In Connexion With Graphs' => 'edsger',
            'The Structure Of The The Multiprogramming System' => 'linus',
        ];

        foreach ($drafts as $title => $handle) {
            Article::query()->firstOrCreate(
                ['slug' => str($title)->slug()->value()],
                [
                    'title' => $title,
                    'body' => 'Placeholder copy for the workbench demo.',
                    'published' => false,
                    'author_id' => $people[$handle]->getKey(),
                ],
            );
        }
    }
}
