<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Enums\EscalationAction;
use ByRcsc\LaravelApproval\Escalation\AutoDecideStrategy;
use ByRcsc\LaravelApproval\Escalation\RecordOnlyStrategy;
use ByRcsc\LaravelApproval\Exceptions\InvalidConfigurationException;
use ByRcsc\LaravelApproval\Facades\Approval;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Support\EscalationStrategies;
use ByRcsc\LaravelApproval\Tests\Stubs\ShoutingStrategy;
use Illuminate\Support\Facades\Date;

it('maps every case to a shipped strategy', function (): void {
    foreach (EscalationAction::cases() as $case) {
        expect(EscalationStrategies::for($case))->toBeString();
    }
});

it('takes a replacement strategy from config', function (): void {
    config()->set('approval.escalation.strategies.record_only', ShoutingStrategy::class);

    expect(EscalationStrategies::for(EscalationAction::RecordOnly))->toBe(ShoutingStrategy::class);
});

it('falls back to the packaged strategy when config has no entry', function (): void {
    config()->set('approval.escalation.strategies', []);

    expect(EscalationStrategies::for(EscalationAction::RecordOnly))->toBe(RecordOnlyStrategy::class);
});

it('runs a replaced strategy instead of the shipped one', function (): void {
    config()->set('approval.escalation.strategies.record_only', ShoutingStrategy::class);

    ShoutingStrategy::$handled = [];

    $request = overdueRequest();

    Date::setTestNow(now()->addMinutes(90));
    Approval::escalateOverdue();

    expect(ShoutingStrategy::$handled)->toHaveCount(1)
        ->and(ShoutingStrategy::$handled[0])->toBe($request->getKey());

    Date::setTestNow();
});

describe('boot-time validation', function (): void {
    it('refuses a case it does not recognise', function (): void {
        $this->bootConfig = ['approval.escalation.strategies' => ['whenever' => RecordOnlyStrategy::class]];

        expect(fn () => $this->refreshApplication())
            ->toThrow(InvalidConfigurationException::class, 'which is not an escalation case');
    });

    it('refuses a strategy class that does not exist', function (): void {
        $this->bootConfig = ['approval.escalation.strategies' => ['record_only' => 'App\\Nope']];

        expect(fn () => $this->refreshApplication())
            ->toThrow(InvalidConfigurationException::class, 'must be the name of a class that exists');
    });

    it('refuses a class that is not a strategy', function (): void {
        $this->bootConfig = ['approval.escalation.strategies' => ['record_only' => ApprovalRequest::class]];

        expect(fn () => $this->refreshApplication())
            ->toThrow(InvalidConfigurationException::class, 'which does not implement');
    });

    it('boots with a partial map, filling the rest from the package', function (): void {
        $this->bootConfig = ['approval.escalation.strategies' => ['record_only' => RecordOnlyStrategy::class]];

        $this->refreshApplication();

        expect(EscalationStrategies::for(EscalationAction::AutoReject))
            ->toBe(AutoDecideStrategy::class);
    });
});
