<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Gmail;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use RobinsonRyan\Mailroom\Contracts\CredentialResolver;
use RobinsonRyan\Mailroom\Credentials\FileCredentialResolver;
use RobinsonRyan\Mailroom\ProviderRegistry;

/**
 * Registers the Gmail adapter and the file credential resolver it reads by default.
 */
final class GmailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(CredentialResolver::class, FileCredentialResolver::class);

        $this->callAfterResolving(ProviderRegistry::class, function (ProviderRegistry $registry): void {
            $registry->extend('gmail', fn (Container $app): GmailProvider => $app->make(GmailProvider::class));
        });
    }
}
