<?php

declare(strict_types=1);

use RobinsonRyan\Mailroom\Gmail\GmailProvider;
use RobinsonRyan\Mailroom\ProviderRegistry;
use RobinsonRyan\Mailroom\Testing\FakeProvider;
use RobinsonRyan\Mailroom\Tests\Feature\Gmail\GmailApi;

it('registers gmail on the provider registry', function (): void {
    GmailApi::appKey();

    expect(app(ProviderRegistry::class)->get('gmail'))->toBeInstanceOf(GmailProvider::class);
});

it('leaves the registry open to a test fake', function (): void {
    $fake = FakeProvider::install();

    expect(app(ProviderRegistry::class)->get('gmail'))->toBe($fake);
});
