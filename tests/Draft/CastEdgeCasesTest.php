<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Enums\RequestState;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Tests\Stubs\InvoiceStatus;
use Illuminate\Support\Carbon;

/**
 * The corners of the cast matrix: values that only stay equal to themselves if
 * every hop - the JSON columns on the request, the casts on the ghost, the
 * normalizer - agrees about what they mean.
 */
describe('rich draft values survive the JSON round trip', function (): void {
    it('applies a draft written with an enum instance rather than its value', function (): void {
        [$request, $invoice] = approveInvoiceDraft(['status' => InvoiceStatus::Paid]);

        expect($request->state)->toBe(RequestState::Approved)
            ->and($invoice?->status)->toBe(InvoiceStatus::Paid);
    });

    it('applies a draft written with a Carbon instance rather than a string', function (): void {
        [$request, $invoice] = approveInvoiceDraft(['sent_at' => Carbon::parse('2026-08-15 14:05:00')]);

        expect($request->state)->toBe(RequestState::Approved)
            ->and($invoice?->sent_at?->toDateTimeString())->toBe('2026-08-15 14:05:00');
    });

    it('keeps a draft key whose value is null through the round trip', function (): void {
        $approver = user('Approver');
        $post = post();
        $workflow = workflow([['approvers' => [$approver]]]);

        $request = $post->submitForApproval($workflow, ['body' => null], user());

        Approval::approve($request, $approver);

        expect($request->refresh()->state)->toBe(RequestState::Approved)
            ->and($post->fresh()?->getAttributeValue('body'))->toBeNull();
    });
});

describe('equivalent writes are not drift', function (): void {
    it('reads a date rewritten as a midnight datetime as unmoved', function (): void {
        [$request] = approveInvoiceDraft(
            ['issued_on' => '2026-08-15'],
            // The raw column changes shape; the date cast reads both as the
            // same day.
            fn ($invoice) => $invoice->forceFill(['issued_on' => '2026-07-01 00:00:00'])->save(),
        );

        expect($request->state)->toBe(RequestState::Approved);
    });

    it('reads a json object rewritten with its keys in another order as unmoved', function (): void {
        $approver = user('Approver');
        $invoice = invoice(['meta' => ['source' => 'portal', 'channel' => 'web']]);
        $workflow = workflow([['approvers' => [$approver]]]);

        $request = $invoice->submitForApproval($workflow, ['meta' => ['source' => 'api']], user());

        // Same pairs, written in the other order. The raw JSON differs; the
        // pairs do not.
        $invoice->forceFill(['meta' => ['channel' => 'web', 'source' => 'portal']])->save();

        Approval::approve($request, $approver);

        expect($request->refresh()->state)->toBe(RequestState::Approved)
            ->and($invoice->fresh()?->meta?->toArray())->toBe(['source' => 'api']);
    });

    it('reads an integer rewritten as a numeric string as unmoved', function (): void {
        [$request, $invoice] = approveInvoiceDraft(
            ['quantity' => 12],
            fn ($invoice) => $invoice->forceFill(['quantity' => '3'])->save(),
        );

        expect($request->state)->toBe(RequestState::Approved)
            ->and($invoice?->getAttributeValue('quantity'))->toBe(12);
    });
});

describe('near-equivalent writes are drift', function (): void {
    it('does not read an emptied string as the null it was', function (): void {
        $approver = user('Approver');
        $post = post();
        $post->forceFill(['body' => null])->save();

        $workflow = workflow([['approvers' => [$approver]]]);
        $request = $post->submitForApproval($workflow, ['body' => 'New body.'], user());

        $post->forceFill(['body' => ''])->save();

        Approval::approve($request, $approver);

        expect($request->refresh()->state)->toBe(RequestState::Conflicted);
    });

    it('reads a reordered json list as moved', function (): void {
        $approver = user('Approver');
        $invoice = invoice(['lines' => [['sku' => 'A1'], ['sku' => 'B2']]]);
        $workflow = workflow([['approvers' => [$approver]]]);

        $request = $invoice->submitForApproval($workflow, ['lines' => [['sku' => 'Z9']]], user());

        // An object's key order is presentation; a list's order is data.
        $invoice->forceFill(['lines' => [['sku' => 'B2'], ['sku' => 'A1']]])->save();

        Approval::approve($request, $approver);

        expect($request->refresh()->state)->toBe(RequestState::Conflicted);
    });

    it('catches a value arriving on an attribute that was null at submit', function (): void {
        $approver = user('Approver');
        $post = post();
        $post->forceFill(['body' => null])->save();

        $workflow = workflow([['approvers' => [$approver]]]);
        $request = $post->submitForApproval($workflow, ['body' => 'New body.'], user());

        $post->forceFill(['body' => 'Somebody got here first.'])->save();

        Approval::approve($request, $approver);

        expect($request->refresh()->state)->toBe(RequestState::Conflicted)
            ->and($post->fresh()?->getAttributeValue('body'))->toBe('Somebody got here first.');
    });
});

describe('a draft that cannot be written', function (): void {
    it('rolls the whole final vote back rather than leaving half of it', function (): void {
        $approver = user('Approver');
        $post = post();
        $workflow = workflow([['approvers' => [$approver]]]);

        // No allow-list on Post, so an attribute the table does not have gets
        // through submit and fails at apply time - inside the vote's
        // transaction, which must take the vote down with it.
        $request = $post->submitForApproval($workflow, ['no_such_column' => 'value'], user());

        expect(fn () => Approval::approve($request, $approver))->toThrow(Exception::class);

        expect($request->refresh()->state)->toBe(RequestState::Pending)
            ->and($request->actions()->where('action', 'approved')->count())->toBe(0)
            ->and($request->actions()->where('action', 'applied')->count())->toBe(0)
            ->and($request->steps()->sole()->state->value)->toBe('active');
    });
});
