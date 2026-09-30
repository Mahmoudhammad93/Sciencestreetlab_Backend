<?php

namespace Tests;

use App\Modules\Migration\Application\Services\WordPress\ActiveMigrationRun;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        // Hard-isolate tests from staging/local MySQL even if phpunit.xml env is overridden.
        putenv('DB_CONNECTION=sqlite');
        putenv('DB_DATABASE=:memory:');
        putenv('DB_URL=');
        putenv('SESSION_DRIVER=array');
        putenv('QUEUE_CONNECTION=sync');
        putenv('MAIL_MAILER=array');
        putenv('BOSTA_ENABLED=false');
        $_ENV['DB_CONNECTION'] = 'sqlite';
        $_ENV['DB_DATABASE'] = ':memory:';
        $_ENV['DB_URL'] = '';
        $_ENV['SESSION_DRIVER'] = 'array';
        $_ENV['QUEUE_CONNECTION'] = 'sync';
        $_ENV['MAIL_MAILER'] = 'array';
        $_ENV['BOSTA_ENABLED'] = 'false';
        $_SERVER['DB_CONNECTION'] = 'sqlite';
        $_SERVER['DB_DATABASE'] = ':memory:';
        $_SERVER['DB_URL'] = '';
        $_SERVER['SESSION_DRIVER'] = 'array';
        $_SERVER['QUEUE_CONNECTION'] = 'sync';
        $_SERVER['MAIL_MAILER'] = 'array';
        $_SERVER['BOSTA_ENABLED'] = 'false';

        parent::setUp();

        // phpunit.xml injects string "false"; ensure config cast cannot be poisoned mid-suite.
        config([
            'session.driver' => 'array',
            'queue.default' => 'sync',
            'mail.default' => 'array',
            'bosta.enabled' => filter_var(env('BOSTA_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'bosta.use_fake' => filter_var(env('BOSTA_USE_FAKE', true), FILTER_VALIDATE_BOOLEAN),
        ]);

        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");
        if ($connection !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException(
                "Refusing to run tests against non-sqlite memory DB (connection={$connection}, database={$database})."
            );
        }
        if (str_contains($database, 'chiefcode_science_street_lab') || str_contains($database, 'wordpress_legacy')) {
            throw new RuntimeException('Refusing to run tests against staging/legacy databases.');
        }

        ActiveMigrationRun::clear();
    }
}
