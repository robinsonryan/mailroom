<?php

declare(strict_types=1);

use RobinsonRyan\Mailroom\MailroomServiceProvider;

it('registers the service provider', function (): void {
    expect(app()->getProviders(MailroomServiceProvider::class))->not->toBeEmpty();
});

it('merges the package config', function (): void {
    expect(config('mailroom.table_prefix'))->toBe('mailroom_');
});
