<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom;

use Illuminate\Support\ServiceProvider;
use RobinsonRyan\Mailroom\Contracts\BodyPdf;
use RobinsonRyan\Mailroom\Contracts\Corrections;
use RobinsonRyan\Mailroom\Support\UnboundBodyPdf;
use RobinsonRyan\Mailroom\Support\UnboundCorrections;
use RobinsonRyan\Mailroom\TypeSets\TypeSetRegistry;

/**
 * Core wiring. Each module (Gmail, watching/typing, PDF) binds its own
 * implementations in its own provider, registered from MODULE_PROVIDERS.
 */
final class MailroomServiceProvider extends ServiceProvider
{
    /** The modules' own providers: each binds its implementations. */
    private const MODULE_PROVIDERS = [
        Gmail\GmailServiceProvider::class,
        Watching\WatchingServiceProvider::class,
        Pdf\PdfServiceProvider::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mailroom.php', 'mailroom');

        $this->app->singleton(ProviderRegistry::class);
        $this->app->singleton(TypeSetRegistry::class);
        $this->app->bindIf(BodyPdf::class, UnboundBodyPdf::class);
        $this->app->bindIf(Corrections::class, UnboundCorrections::class);
        $this->app->singleton(Mailroom::class);

        foreach (self::MODULE_PROVIDERS as $provider) {
            $this->app->register($provider);
        }
    }

    public function boot(): void
    {
        $this->publishConfig();
        $this->loadMigrations();
        $this->publishMigrations();
    }

    private function publishConfig(): void
    {
        $this->publishes([
            __DIR__.'/../config/mailroom.php' => config_path('mailroom.php'),
        ], 'mailroom-config');
    }

    /**
     * Load the package's migrations unless the consumer has published them —
     * publishing means they took ownership, and loading them too runs each
     * migration twice.
     */
    private function loadMigrations(): void
    {
        if (! $this->migrationsPublished()) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }
    }

    private function publishMigrations(): void
    {
        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'mailroom-migrations');
    }

    private function migrationsPublished(): bool
    {
        return count(glob(database_path('migrations/*_create_mailroom_*.php')) ?: []) > 0;
    }
}
