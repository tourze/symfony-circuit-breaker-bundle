<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Factory;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * 测试环境中的数据库连接工厂
 */
final class TestConnectionFactory
{
    private static ?Connection $connection = null;

    private static bool $available = true;

    public static function create(): Connection
    {
        if (null !== self::$connection) {
            return self::$connection;
        }

        try {
            self::$connection = DriverManager::getConnection([
                'driver' => 'pdo_mysql',
                'host' => $_ENV['DATABASE_HOST'] ?? '127.0.0.1',
                'port' => $_ENV['DATABASE_PORT'] ?? '3306',
                'user' => $_ENV['DATABASE_USER'] ?? 'root',
                'password' => $_ENV['DATABASE_PASSWORD'] ?? '',
                'dbname' => $_ENV['DATABASE_NAME'] ?? 'circuit_breaker_test',
            ]);

            // 验证连接
            self::$connection->executeQuery('SELECT 1');
            self::$available = true;
        } catch (\Throwable $e) {
            // 创建一个 SQLite 内存数据库作为后备
            self::$connection = DriverManager::getConnection([
                'driver' => 'pdo_sqlite',
                'memory' => true,
            ]);
            self::$available = false;
        }

        return self::$connection;
    }

    public static function isAvailable(): bool
    {
        if (null === self::$connection) {
            self::create();
        }

        return self::$available;
    }

    public static function reset(): void
    {
        self::$connection = null;
        self::$available = true;
    }
}
