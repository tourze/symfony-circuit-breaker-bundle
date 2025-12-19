<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Factory;

/**
 * 测试环境中 Redis 工厂类
 *
 * 用于在测试环境中创建 Redis 实例
 */
final class TestRedisFactory
{
    /**
     * 创建 Redis 实例
     *
     * 如果 Redis 不可用，返回一个空的 Redis 实例（测试会检查连接并跳过）
     */
    public static function create(): \Redis
    {
        $redis = new \Redis();

        try {
            $connected = $redis->connect(
                $_ENV['REDIS_HOST'] ?? '127.0.0.1',
                (int) ($_ENV['REDIS_PORT'] ?? 6379),
                1.0 // 1秒超时
            );

            if ($connected && isset($_ENV['REDIS_PASSWORD']) && '' !== $_ENV['REDIS_PASSWORD']) {
                $redis->auth($_ENV['REDIS_PASSWORD']);
            }
        } catch (\Throwable) {
            // 连接失败，返回未连接的实例，测试会检查并跳过
        }

        return $redis;
    }
}
