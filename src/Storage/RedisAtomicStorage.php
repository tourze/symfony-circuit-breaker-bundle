<?php

namespace Tourze\Symfony\CircuitBreaker\Storage;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Tourze\RedisDedicatedConnectionBundle\Attribute\WithDedicatedConnection;
use Tourze\Symfony\CircuitBreaker\Model\CallResult;
use Tourze\Symfony\CircuitBreaker\Model\CircuitBreakerState;
use Tourze\Symfony\CircuitBreaker\Model\MetricsSnapshot;

/**
 * Redis原子操作存储实现
 * 
 * 使用Redis的原子操作来存储熔断器状态和指标
 */
#[WithDedicatedConnection('circuit_breaker')]
class RedisAtomicStorage implements CircuitBreakerStorageInterface
{
    private const KEY_PREFIX = 'circuit:';
    private const STATE_KEY_SUFFIX = ':state';
    private const METRICS_KEY_SUFFIX = ':metrics';
    private const LOCK_KEY_SUFFIX = ':lock';
    private const CIRCUITS_SET_KEY = 'circuit:all';
    
    private const DEFAULT_WINDOW_SIZE = 60; // 默认60秒窗口

    public function __construct(
        private readonly \Redis $redis,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
    }

    public function saveState(string $name, CircuitBreakerState $state): bool
    {
        $key = $this->getStateKey($name);

        $result = $this->redis->hMset($key, [
            'state' => $state->getState()->value,
            'timestamp' => (string)$state->getTimestamp(),
            'attempt_count' => (string)$state->getAttemptCount(),
        ]);

        if ($result) {
            // 添加到熔断器集合
            $this->redis->sAdd(self::CIRCUITS_SET_KEY, $name);
            // 设置过期时间（7天）
            $this->redis->expire($key, 604800);
        }

        return $result;
    }

    private function getStateKey(string $name): string
    {
        return self::KEY_PREFIX . $name . self::STATE_KEY_SUFFIX;
    }

    public function getState(string $name): CircuitBreakerState
    {
        $key = $this->getStateKey($name);
        $data = $this->redis->hGetAll($key);

        if (empty($data)) {
            return new CircuitBreakerState();
        }

        try {
            return CircuitBreakerState::fromArray([
                'state' => $data['state'] ?? 'closed',
                'timestamp' => (int)($data['timestamp'] ?? 0),
                'attemptCount' => (int)($data['attempt_count'] ?? 0),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to parse circuit breaker state', [
                'circuit' => $name,
                'error' => $e->getMessage(),
                'data' => $data,
            ]);
            return new CircuitBreakerState();
        }
    }

    public function recordCall(string $name, CallResult $result): void
    {
        $metricsKey = $this->getMetricsKey($name);
        $timestamp = $result->getTimestamp();
        $member = $result->toString();

        // 添加到有序集合
        $this->redis->zAdd($metricsKey, $timestamp, sprintf('%d:%s', $timestamp, $member));

        // 清理过期数据（保留最近的窗口数据）
        $windowStart = time() - self::DEFAULT_WINDOW_SIZE;
        $this->redis->zRemRangeByScore($metricsKey, '0', (string)($windowStart - 1));

        // 设置过期时间
        $this->redis->expire($metricsKey, self::DEFAULT_WINDOW_SIZE * 2);

        // 添加到熔断器集合
        $this->redis->sAdd(self::CIRCUITS_SET_KEY, $name);
    }

    private function getMetricsKey(string $name): string
    {
        return self::KEY_PREFIX . $name . self::METRICS_KEY_SUFFIX;
    }

    public function getMetricsSnapshot(string $name, int $windowSize): MetricsSnapshot
    {
        $metricsKey = $this->getMetricsKey($name);
        $windowStart = time() - $windowSize;

        // 获取窗口内的所有数据
        $data = $this->redis->zRangeByScore($metricsKey, (string)$windowStart, (string)time());

        $totalCalls = 0;
        $successCalls = 0;
        $failedCalls = 0;
        $slowCalls = 0;
        $totalDuration = 0.0;
        $slowCallThreshold = (float)($_ENV['CIRCUIT_BREAKER_SLOW_CALL_THRESHOLD'] ?? 1000); // 默认1秒

        foreach ($data as $entry) {
            // 格式: timestamp:type:duration
            if (preg_match('/^(\d+):(\w+):([\d.]+)$/', $entry, $matches)) {
                $totalCalls++;
                $type = $matches[2];
                $duration = (float)$matches[3];
                $totalDuration += $duration;

                if ($type === 'success') {
                    $successCalls++;
                } else {
                    $failedCalls++;
                }

                if ($duration > $slowCallThreshold) {
                    $slowCalls++;
                }
            }
        }

        $avgResponseTime = $totalCalls > 0 ? $totalDuration / $totalCalls : 0.0;

        return new MetricsSnapshot(
            totalCalls: $totalCalls,
            successCalls: $successCalls,
            failedCalls: $failedCalls,
            slowCalls: $slowCalls,
            notPermittedCalls: 0, // 这个需要单独统计
            avgResponseTime: $avgResponseTime,
            timestamp: time()
        );
    }

    public function getAllCircuitNames(): array
    {
        return $this->redis->sMembers(self::CIRCUITS_SET_KEY) ?: [];
    }

    public function deleteCircuit(string $name): void
    {
        $stateKey = $this->getStateKey($name);
        $metricsKey = $this->getMetricsKey($name);

        $this->redis->del($stateKey, $metricsKey);
        $this->redis->srem(self::CIRCUITS_SET_KEY, $name);
    }

    public function acquireLock(string $name, string $token, int $ttl): bool
    {
        $lockKey = $this->getLockKey($name);

        // SET key value NX PX milliseconds
        return $this->redis->set($lockKey, $token, ['nx', 'px' => $ttl * 1000]);
    }

    private function getLockKey(string $name): string
    {
        return self::KEY_PREFIX . $name . self::LOCK_KEY_SUFFIX;
    }

    public function releaseLock(string $name, string $token): bool
    {
        $lockKey = $this->getLockKey($name);

        // 使用Lua脚本确保原子性
        $script = <<<'LUA'
if redis.call("get", KEYS[1]) == ARGV[1] then
    return redis.call("del", KEYS[1])
else
    return 0
end
LUA;

        return (bool)$this->redis->eval($script, [$lockKey, $token], 1);
    }

    public function isAvailable(): bool
    {
        try {
            return $this->redis->ping();
        } catch (\Throwable $e) {
            $this->logger->error('Redis connection failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}