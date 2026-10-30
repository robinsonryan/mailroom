<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom;

use Illuminate\Support\ServiceProvider;

final class MailroomServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mailroom.php', 'mailroom');
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
