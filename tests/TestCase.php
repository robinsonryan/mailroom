<?php

declare(strict_types=1);

namespace RobinsonRyan\Mailroom\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;
use PDO;
use RobinsonRyan\Mailroom\MailroomServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    private static bool $databaseEnsured = false;

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
            'database' => $this->resolveDatabaseName(),
            'username' => env('DB_USERNAME', 'db'),
            'password' => env('DB_PASSWORD', 'db'),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);
    }

    /**
     * Each worktree under .claude/worktrees/<slug>/ tests on its own
     * testing_wt_<slug> database, created on demand, so parallel builders
     * never share a schema.
     */
    private function resolveDatabaseName(): string
    {
        $override = env('MAILROOM_TEST_DB_DATABASE');

        if (is_string($override) && $override !== '') {
            return $override;
        }

        if (preg_match('#/\.claude/worktrees/([^/]+)/#', __DIR__, $matches) === 1) {
            $name = 'testing_wt_'.preg_replace('/[^a-z0-9_]+/', '_', strtolower($matches[1]));
            $this->ensureDatabaseExists($name);

            return $name;
        }

        return 'testing';
    }

    private function ensureDatabaseExists(string $name): void
    {
        if (self::$databaseEnsured) {
            return;
        }

        self::$databaseEnsured = true;
        $pdo = new PDO(
            sprintf('pgsql:host=%s;port=%d;dbname=postgres', (string) env('DB_HOST', 'db'), (int) env('DB_PORT', 5432)),
            (string) env('DB_USERNAME', 'db'),
            (string) env('DB_PASSWORD', 'db'),
        );
        $statement = $pdo->prepare('select 1 from pg_database where datname = ?');
        $statement->execute([$name]);

        if ($statement->fetchColumn() === false) {
            $pdo->exec('create database "'.$name.'"');
        }
    }
}
