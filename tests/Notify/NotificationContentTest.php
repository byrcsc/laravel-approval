<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Events\ApprovalRejected;
use ByRcsc\LaravelApproval\Events\ApprovalReturned;
use ByRcsc\LaravelApproval\Events\ApprovalSubmitted;
use ByRcsc\LaravelApproval\Notifications\ApprovalApprovedNotification;
use ByRcsc\LaravelApproval\Notifications\ApprovalNotification;
use ByRcsc\LaravelApproval\Notifications\ApprovalRejectedNotification;
use ByRcsc\LaravelApproval\Notifications\ApprovalRequestedNotification;
use ByRcsc\LaravelApproval\Notifications\ApprovalReturnedNotification;
use ByRcsc\LaravelApproval\Tests\Stubs\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\ServiceProvider;

function submittedEvent(string $title = 'Quarterly report'): ApprovalSubmitted
{
    $request = post($title)->submitForApproval(
        workflow([['approvers' => [user('Approver')]]]),
        null,
        user('Author'),
    );

    return new ApprovalSubmitted($request, $request->stages()->sole());
}

function mailBody(MailMessage $mail): string
{
    return (string) $mail->render();
}

/**
 * @return list<class-string<ApprovalNotification>>
 */
function concreteNotifications(): array
{
    $classes = [];

    foreach (classesIn('Notifications') as $name) {
        /** @var class-string<ApprovalNotification> $class */
        $class = 'ByRcsc\\LaravelApproval\\Notifications\\'.$name;

        if ($class !== ApprovalNotification::class) {
            $classes[] = $class;
        }
    }

    return $classes;
}

describe('the packaged content', function (): void {
    it('renders a mail body for every notification it ships', function (): void {
        $event = submittedEvent();

        expect(concreteNotifications())->toHaveCount(7);

        foreach (concreteNotifications() as $class) {
            $mail = (new $class($event))->toMail(new User);
            $body = mailBody($mail);

            expect($mail->subject)->toBeString()->not->toBe('')
                ->and($body)->toContain('Quarterly report')
                ->and($body)->not->toContain('approval::');
        }
    });

    it('carries the request reference into the body', function (): void {
        $event = submittedEvent();
        $body = mailBody((new ApprovalRequestedNotification($event))->toMail(new User));

        expect($body)->toContain((string) $event->request->getAttributeValue('ulid'));
    });

    it('names a deleted record with the translated stand-in', function (): void {
        $event = submittedEvent();
        $event->request->approvable?->delete();
        $event->request->unsetRelation('approvable');

        $notification = new ApprovalRequestedNotification($event);

        expect($notification->subject())->toBe('a deleted record')
            ->and($notification->toMail(new User)->subject)->toBe('Approval needed: a deleted record');
    });

    it('leaves the database payload free of markup and wording', function (): void {
        $event = submittedEvent();

        expect((new ApprovalRequestedNotification($event))->toArray(new User))->toBe([
            'request' => (string) $event->request->getAttributeValue('ulid'),
            'state' => 'pending',
            'subject' => 'Quarterly report',
        ]);
    });
});

describe('the reason line', function (): void {
    it('appears when a decision carried one', function (): void {
        $event = submittedEvent();
        $rejected = new ApprovalRejected(
            $event->request,
            $event->request->stages()->sole(),
            user('Approver'),
            'Missing a purchase order.',
        );

        expect(mailBody((new ApprovalRejectedNotification($rejected))->toMail(new User)))
            ->toContain('Reason: Missing a purchase order.');
    });

    it('appears on a return for revision too', function (): void {
        $event = submittedEvent();
        $returned = new ApprovalReturned(
            $event->request,
            $event->request->stages()->sole(),
            user('Approver'),
            'Fix the title.',
        );

        expect(mailBody((new ApprovalReturnedNotification($returned))->toMail(new User)))
            ->toContain('Reason: Fix the title.');
    });

    it('is left out when there is none', function (): void {
        $body = mailBody((new ApprovalRejectedNotification(submittedEvent()))->toMail(new User));

        expect($body)->not->toContain('Reason:');
    });
});

describe('translation', function (): void {
    /**
     * @param  array<string, string>  $lines
     */
    function published(array $lines, string $locale): void
    {
        Lang::addLines($lines, $locale, 'approval');
    }

    it('follows the application locale in both the subject and the body', function (): void {
        published([
            'approval.requested.subject' => 'Se necesita aprobacion: :subject',
            'approval.requested.body' => 'Le han pedido aprobar :subject.',
            'approval.reference' => 'Referencia: :reference.',
        ], 'es');

        $notification = new ApprovalRequestedNotification(submittedEvent());

        App::setLocale('es');
        $mail = $notification->toMail(new User);
        $body = mailBody($mail);

        expect($mail->subject)->toBe('Se necesita aprobacion: Quarterly report')
            ->and($body)->toContain('Le han pedido aprobar Quarterly report.')
            ->and($body)->toContain('Referencia:');
    });

    it('falls back to English for a line the locale leaves out', function (): void {
        published(['approval.requested.subject' => 'Aprobacion: :subject'], 'es');

        App::setLocale('es');
        $body = mailBody((new ApprovalRequestedNotification(submittedEvent()))->toMail(new User));

        expect($body)->toContain('You have been asked to approve');
    });

    it('lets a placeholder sit anywhere in the line', function (): void {
        published(['approval.requested.subject' => ':subject needs approving'], 'en');

        expect((new ApprovalRequestedNotification(submittedEvent()))->toMail(new User)->subject)
            ->toBe('Quarterly report needs approving');
    });

    it('shows the key rather than failing when an override is not a line', function (): void {
        published(['approval.approved' => ['subject' => ['not', 'a line']]], 'en');

        expect((new ApprovalApprovedNotification(submittedEvent()))->toMail(new User)->subject)
            ->toBe('approval::approval.approved.subject');
    });
});

describe('publishing', function (): void {
    it('registers a publish tag for the translations and the views', function (): void {
        expect(array_keys(ServiceProvider::$publishGroups))
            ->toContain('approval-translations')
            ->toContain('approval-views');
    });

    it('ships an English line file and a view for every content key', function (): void {
        $root = dirname(__DIR__, 2);

        /** @var array<string, mixed> $lines */
        $lines = require $root.'/resources/lang/en/approval.php';

        $keys = array_keys(array_filter($lines, is_array(...)));
        $views = array_map(
            fn (string $file): string => basename($file, '.blade.php'),
            glob($root.'/resources/views/mail/*.blade.php') ?: [],
        );

        sort($keys);
        sort($views);

        expect($keys)->toBe($views)->toBe([
            'approved', 'assigned', 'conflicted', 'overdue',
            'rejected', 'requested', 'returned',
        ]);
    });
});
