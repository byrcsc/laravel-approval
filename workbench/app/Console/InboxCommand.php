<?php

declare(strict_types=1);

namespace Workbench\App\Console;

use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use Workbench\App\Models\PurchaseOrder;
use Workbench\App\Models\User;

/**
 * One person's approval screen: what is waiting on them, what they submitted,
 * and what they were told about.
 *
 * The package ships the queries an inbox is built from and no inbox. This is
 * what one costs to write.
 *
 * The awaiting query is narrower than "requests I am assigned to". A delegated
 * assignment belongs to the delegate, so it appears in exactly one inbox, and a
 * request the person has already approved leaves theirs while the stage waits on
 * the others.
 */
final class InboxCommand extends Command
{
    protected $signature = 'demo:inbox
        {user : Who to report for}
        {--model : Also list the purchase orders awaiting them, through the model scope}';

    protected $description = 'List the approval requests waiting on somebody, and the ones they submitted';

    public function handle(): int
    {
        $identifier = $this->argument('user');
        $user = $this->person(is_scalar($identifier) ? (string) $identifier : '');

        if ($user === null) {
            return self::FAILURE;
        }

        $this->reportAwaiting($user);
        $this->reportSubmitted($user);
        $this->reportNotifications($user);

        if ($this->option('model') === true) {
            $this->reportModelScope($user);
        }

        return self::SUCCESS;
    }

    private function reportAwaiting(User $user): void
    {
        $requests = Approval::awaiting($user)->withProgress()->get();

        $this->components->info(sprintf('Waiting on %s', $this->label($user)));

        if ($requests->isEmpty()) {
            $this->line('  <fg=gray>Nothing.</>');

            return;
        }

        foreach ($requests as $request) {
            $stage = $request->currentStage();
            $progress = $request->stageProgress();

            $this->components->twoColumnDetail(
                sprintf('%s  %s', $this->reference($request), $this->label($request->approvable)),
                sprintf(
                    '%s %d/%d%s',
                    (string) ($stage?->getAttributeValue('name') ?? '-'),
                    $progress?->approvalsReceived ?? 0,
                    $progress?->approvalsRequired ?? 0,
                    $this->overdue($request) ? ' <fg=yellow>overdue</>' : '',
                ),
            );
        }
    }

    private function reportSubmitted(User $user): void
    {
        $requests = ApprovalRequest::query()
            ->where('requester_type', $user->getMorphClass())
            ->where('requester_id', $user->getKey())
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $this->newLine();
        $this->components->info('Submitted by them');

        if ($requests->isEmpty()) {
            $this->line('  <fg=gray>Nothing.</>');

            return;
        }

        foreach ($requests as $request) {
            $state = $request->getAttributeValue('state');

            $this->components->twoColumnDetail(
                sprintf('%s  %s', $this->reference($request), $this->label($request->approvable)),
                sprintf(
                    '<fg=%s>%s</>%s',
                    $state === RequestState::Conflicted ? 'yellow' : 'default',
                    (string) ($state?->value ?? '?'),
                    $request->canBeWithdrawnBy($user) ? ' <fg=gray>(withdrawable)</>' : '',
                ),
            );
        }
    }

    /**
     * The `database` notification channel, which the demo enables alongside
     * `mail`. Delivery is opt-in in the package and off by default.
     */
    private function reportNotifications(User $user): void
    {
        $notifications = $user->unreadNotifications()->limit(10)->get();

        $this->newLine();
        $this->components->info('Unread notifications');

        if ($notifications->isEmpty()) {
            $this->line('  <fg=gray>None.</>');

            return;
        }

        foreach ($notifications as $notification) {
            $data = $notification->data;

            $this->components->twoColumnDetail(
                class_basename((string) $notification->type),
                sprintf(
                    '%s - %s',
                    is_array($data) ? $this->scalar($data['state'] ?? '?') : '?',
                    is_array($data) ? $this->scalar($data['subject'] ?? '?') : '?',
                ),
            );
        }
    }

    /**
     * The same question asked of the model rather than of the request, which is
     * what a listing screen filters on.
     */
    private function reportModelScope(User $user): void
    {
        $orders = PurchaseOrder::query()->awaitingApprovalBy($user)->get();

        $this->newLine();
        $this->components->info('Purchase orders awaiting them (model scope)');

        if ($orders->isEmpty()) {
            $this->line('  <fg=gray>None.</>');

            return;
        }

        foreach ($orders as $order) {
            $this->components->twoColumnDetail($this->label($order), $order->formattedAmount());
        }
    }

    private function overdue(ApprovalRequest $request): bool
    {
        $deadline = $request->currentStage()?->getAttributeValue('deadline_at');

        return $deadline !== null && $deadline->isPast();
    }
}
