<?php

declare(strict_types=1);

use ByRcsc\LaravelApproval\Events\ApprovalSubmitted;
use ByRcsc\LaravelApproval\Exceptions\InvalidConfigurationException;
use ByRcsc\LaravelApproval\Models\ApprovalRequest;
use ByRcsc\LaravelApproval\Notifications\ApprovalRequestedNotification;

it('refuses a map keyed by something that is not a class', function (): void {
    $this->bootConfig = ['approval.notifications.map' => ['not.a.class' => ApprovalRequestedNotification::class]];

    expect(fn () => $this->refreshApplication())
        ->toThrow(InvalidConfigurationException::class, 'which is not a class that exists');
});

it('refuses a notification class that does not exist', function (): void {
    $this->bootConfig = ['approval.notifications.map' => [ApprovalSubmitted::class => 'App\\Nope']];

    expect(fn () => $this->refreshApplication())
        ->toThrow(InvalidConfigurationException::class, 'must be null or the name of a class that exists');
});

it('refuses a class that is not an approval notification', function (): void {
    $this->bootConfig = ['approval.notifications.map' => [ApprovalSubmitted::class => ApprovalRequest::class]];

    expect(fn () => $this->refreshApplication())
        ->toThrow(InvalidConfigurationException::class, 'which does not extend');
});

it('validates the map even while notifications are off', function (): void {
    // A typo should surface the day it is written, not the day somebody
    // finally turns notifications on.
    $this->bootConfig = [
        'approval.notifications.enabled' => false,
        'approval.notifications.map' => [ApprovalSubmitted::class => 'App\\Nope'],
    ];

    expect(fn () => $this->refreshApplication())->toThrow(InvalidConfigurationException::class);
});
