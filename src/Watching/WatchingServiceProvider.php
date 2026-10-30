<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Watching;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use RobinsonRyan\Mailroom\Console\WatchCommand;
use RobinsonRyan\Mailroom\Console\WebhooksCommand;
use RobinsonRyan\Mailroom\Contracts\Corrections;
use RobinsonRyan\Mailroom\Contracts\DecisionGateway;
use RobinsonRyan\Mailroom\Events\MessageTyped;
use RobinsonRyan\Mailroom\Gateway\HttpDecisionGateway;
use RobinsonRyan\Mailroom\Typing\PersonCorrections;
use RobinsonRyan\Mailroom\Webhooks\QueueDeliveries;

/**
 * Watching, typing, corrections, the gateway client and webhooks. Registered
 * from MailroomServiceProvider::register(), after its placeholder bindings.
 */
final class WatchingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DecisionGateway::class, HttpDecisionGateway::class);
        $this->app->bind(Corrections::class, PersonCorrections::class);

        $this->commands([WatchCommand::class, WebhooksCommand::class]);
    }

    public function boot(): void
    {
        Event::listen(MessageTyped::class, QueueDeliveries::class);
    }
}
