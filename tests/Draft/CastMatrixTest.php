<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Tests\Stubs\InvoiceStatus;

/**
 * The stale check compares cast values, not raw columns. These are the casts
 * where the difference decides whether the comparison is right or nonsense.
 */
describe('a record nobody touched applies cleanly', function (): void {
    it('survives a date cast', function (): void {
        [$request, $invoice] = approveInvoiceDraft(['issued_on' => '2026-08-15']);

        expect($request->state)->toBe(RequestState::Approved)
            ->and($invoice?->issued_on?->toDateString())->toBe('2026-08-15');
    });

    it('survives a datetime cast', function (): void {
        [$request, $invoice] = approveInvoiceDraft(['sent_at' => '2026-08-15 14:05:00']);

        expect($request->state)->toBe(RequestState::Approved)
            ->and($invoice?->sent_at?->toDateTimeString())->toBe('2026-08-15 14:05:00');
    });

    it('survives an enum cast', function (): void {
        [$request, $invoice] = approveInvoiceDraft(['status' => InvoiceStatus::Sent->value]);

        expect($request->state)->toBe(RequestState::Approved)
            ->and($invoice?->status)->toBe(InvoiceStatus::Sent);
    });

    it('survives an encrypted cast, whose ciphertext differs on every write', function (): void {
        [$request, $invoice] = approveInvoiceDraft(['account_number' => 'GB99 8765 4321']);

        expect($request->state)->toBe(RequestState::Approved)
            ->and($invoice?->getAttributeValue('account_number'))->toBe('GB99 8765 4321');
    });

    it('survives a decimal cast', function (): void {
        [$request, $invoice] = approveInvoiceDraft(['total' => '999.99']);

        expect($request->state)->toBe(RequestState::Approved)
            ->and($invoice?->getAttributeValue('total'))->toBe('999.99');
    });

    it('survives float, integer, and boolean casts', function (): void {
        [$request, $invoice] = approveInvoiceDraft(['rate' => 0.175, 'quantity' => 12, 'settled' => true]);

        expect($request->state)->toBe(RequestState::Approved)
            ->and($invoice?->getAttributeValue('rate'))->toBe(0.175)
            ->and($invoice?->getAttributeValue('quantity'))->toBe(12)
            ->and($invoice?->getAttributeValue('settled'))->toBeTrue();
    });

    it('survives an array cast', function (): void {
        [$request, $invoice] = approveInvoiceDraft(['lines' => [['sku' => 'B2', 'qty' => 5]]]);

        expect($request->state)->toBe(RequestState::Approved)
            ->and(canonical($invoice?->getAttributeValue('lines')))->toBe(canonical([['sku' => 'B2', 'qty' => 5]]));
    });

    it('survives an ArrayObject cast', function (): void {
        [$request, $invoice] = approveInvoiceDraft(['meta' => ['source' => 'api']]);

        expect($request->state)->toBe(RequestState::Approved)
            ->and($invoice?->meta?->toArray())->toBe(['source' => 'api']);
    });
});

describe('an encrypted attribute', function (): void {
    it('does not report drift when it was only re-saved', function (): void {
        [$request, $invoice] = approveInvoiceDraft(
            ['account_number' => 'GB99 8765 4321'],
            // Re-saving the same plaintext produces different ciphertext. A
            // raw comparison would call this drift; a cast-aware one does not.
            fn ($invoice) => $invoice->forceFill(['account_number' => 'GB00 1234 5678'])->save(),
        );

        expect($request->state)->toBe(RequestState::Approved)
            ->and($invoice?->getAttributeValue('account_number'))->toBe('GB99 8765 4321');
    });

    it('does report drift when the plaintext actually changed', function (): void {
        [$request, $invoice] = approveInvoiceDraft(
            ['account_number' => 'GB99 8765 4321'],
            fn ($invoice) => $invoice->forceFill(['account_number' => 'IE11 2222 3333'])->save(),
        );

        expect($request->state)->toBe(RequestState::Conflicted)
            ->and($invoice?->getAttributeValue('account_number'))->toBe('IE11 2222 3333');
    });
});

describe('drift detection per cast', function (): void {
    it('catches a moved date', function (): void {
        [$request] = approveInvoiceDraft(
            ['issued_on' => '2026-08-15'],
            fn ($invoice) => $invoice->forceFill(['issued_on' => '2026-07-09'])->save(),
        );

        expect($request->state)->toBe(RequestState::Conflicted);
    });

    it('catches a moved enum', function (): void {
        [$request] = approveInvoiceDraft(
            ['status' => InvoiceStatus::Paid->value],
            fn ($invoice) => $invoice->forceFill(['status' => InvoiceStatus::Sent])->save(),
        );

        expect($request->state)->toBe(RequestState::Conflicted);
    });

    it('catches a moved decimal', function (): void {
        [$request] = approveInvoiceDraft(
            ['total' => '999.99'],
            fn ($invoice) => $invoice->forceFill(['total' => '200.00'])->save(),
        );

        expect($request->state)->toBe(RequestState::Conflicted);
    });

    it('catches a rewritten json array', function (): void {
        [$request] = approveInvoiceDraft(
            ['lines' => [['sku' => 'B2', 'qty' => 5]]],
            fn ($invoice) => $invoice->forceFill(['lines' => [['sku' => 'C3', 'qty' => 1]]])->save(),
        );

        expect($request->state)->toBe(RequestState::Conflicted);
    });

    it('does not call a json object re-saved with the same pairs a change', function (): void {
        [$request] = approveInvoiceDraft(
            ['meta' => ['source' => 'api']],
            // The value it already held, written again.
            fn ($invoice) => $invoice->forceFill(['meta' => ['source' => 'portal']])->save(),
        );

        expect($request->state)->toBe(RequestState::Approved);
    });

    it('does not call trailing decimal zeros a change', function (): void {
        [$request] = approveInvoiceDraft(
            ['reference' => 'INV-3000'],
            fn ($invoice) => $invoice->forceFill(['total' => '125.5'])->save(),
        );

        expect($request->state)->toBe(RequestState::Approved);
    });
});
