<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use RobinsonRyan\Mailroom\MailroomServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            MailroomServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // The schema uses PostgreSQL's native uuidv7() as a column default, which
        // SQLite cannot express — so the suite runs against DDEV's Postgres on a
        // database of its own. Do not "simplify" this to SQLite.
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', 'db'),
            'port' => (int) env('DB_PORT', 5432),
            'database' => env('DB_DATABASE', 'testing'),
            'username' => env('DB_USERNAME', 'db'),
            'password' => env('DB_PASSWORD', 'db'),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);
    }
}
