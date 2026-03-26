<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DatabaseDefaultsTest extends TestCase
{
    #[Test]
    public function it_uses_mysql_as_the_database_fallback(): void
    {
        $originalEnv = getenv('DB_CONNECTION');
        $originalServer = $_SERVER['DB_CONNECTION'] ?? null;
        $originalEnvironment = $_ENV['DB_CONNECTION'] ?? null;

        putenv('DB_CONNECTION');
        unset($_SERVER['DB_CONNECTION'], $_ENV['DB_CONNECTION']);

        $config = require base_path('config/database.php');

        if ($originalEnv !== false) {
            putenv("DB_CONNECTION={$originalEnv}");
        } else {
            putenv('DB_CONNECTION');
        }

        if ($originalServer !== null) {
            $_SERVER['DB_CONNECTION'] = $originalServer;
        } else {
            unset($_SERVER['DB_CONNECTION']);
        }

        if ($originalEnvironment !== null) {
            $_ENV['DB_CONNECTION'] = $originalEnvironment;
        } else {
            unset($_ENV['DB_CONNECTION']);
        }

        $this->assertSame('mysql', $config['default']);
    }
}
