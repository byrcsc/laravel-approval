<?php

declare(strict_types=1);

it('canonicalizes object keys without reordering list values', function (): void {
    expect(canonical([
        'z' => ['second', 'first'],
        'a' => ['z' => 1, 'a' => 2],
    ]))->toBe([
        'a' => ['a' => 2, 'z' => 1],
        'z' => ['second', 'first'],
    ]);
});
