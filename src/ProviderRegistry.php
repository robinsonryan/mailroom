<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom;

use Closure;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use RobinsonRyan\Mailroom\Contracts\MailProvider;
use RobinsonRyan\Mailroom\Models\Mailbox;

/**
 * Provider adapters by name (`gmail`). Adapters register in their own service
 * provider: `$registry->extend('gmail', fn ($app) => $app->make(GmailProvider::class))`.
 */
final class ProviderRegistry
{
    /** @var array<string, Closure(Container): MailProvider> */
    private array $factories = [];

    /** @var array<string, MailProvider> */
    private array $resolved = [];

    public function __construct(private readonly Container $container) {}

    /**
     * @param  Closure(Container): MailProvider  $factory
     */
    public function extend(string $name, Closure $factory): void
    {
        $this->factories[$name] = $factory;
        unset($this->resolved[$name]);
    }

    /** Replace a provider with an instance (tests: FakeProvider). */
    public function swap(string $name, MailProvider $provider): void
    {
        $this->resolved[$name] = $provider;
    }

    public function get(string $name): MailProvider
    {
        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        $factory = $this->factories[$name] ?? throw new InvalidArgumentException("No mail provider named {$name}.");

        return $this->resolved[$name] = $factory($this->container);
    }

    public function for(Mailbox $mailbox): MailProvider
    {
        return $this->get($mailbox->provider);
    }
}
