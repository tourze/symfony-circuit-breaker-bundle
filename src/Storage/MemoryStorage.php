<?php

namespace Tourze\Symfony\CircuitBreaker\Storage;

use Tourze\Symfony\CircuitBreaker\Model\CallResult;
use Tourze\Symfony\CircuitBreaker\Model\CircuitBreakerState;
use Tourze\Symfony\CircuitBreaker\Model\MetricsSnapshot;

/**
 * 内存存储实现
 *
 * 最后的兜底方案，确保熔断器始终可用
 * 注意：此存储不支持跨进程/请求的数据共享
 */
class MemoryStorage implements CircuitBreakerStorageInterface
{
    /**
     * @var array<string, CircuitBreakerState>
     */
    private array $states = [];

    /**
     * @var array<string, array<CallResult>>
     */
    private array $calls = [];

    /**
     * @var array<string, array{token: string, expireAt: int}>
     */
    private array $locks = [];

    public function getState(string $name): CircuitBreakerState
    {
        return $this->states[$name] ?? new CircuitBreakerState();
    }

    public function saveState(string $name, CircuitBreakerState $state): bool
    {
        $this->states[$name] = $state;

        return true;
    }

    public function recordCall(string $name, CallResult $result): void
    {
        if (!isset($this->calls[$name])) {
            $this->calls[$name] = [];
        }

        // 添加新调用
        $this->calls[$name][] = $result;

        // 清理过期数据（保留最近2分钟的数据）
        $cutoff = time() - 120;
        $this->calls[$name] = array_filter(
            $this->calls[$name],
            fn (CallResult $call) => $call->getTimestamp() > $cutoff
        );

        // 重新索引数组
        $this->calls[$name] = array_values($this->calls[$name]);
    }

    public function getMetricsSnapshot(string $name, int $windowSize): MetricsSnapshot
    {
        $calls = $this->calls[$name] ?? [];
        $windowStart = time() - $windowSize;
        $slowCallThreshold = (float) ($_ENV['CIRCUIT_BREAKER_SLOW_CALL_THRESHOLD'] ?? 1000);

        $totalCalls = 0;
        $successCalls = 0;
        $failedCalls = 0;
        $slowCalls = 0;
        $totalDuration = 0.0;

        foreach ($calls as $call) {
            if ($call->getTimestamp() < $windowStart) {
                continue;
            }

            ++$totalCalls;
            $totalDuration += $call->getDuration();

            if ($call->isSuccess()) {
                ++$successCalls;
            } else {
                ++$failedCalls;
            }

            if ($call->isSlowCall($slowCallThreshold)) {
                ++$slowCalls;
            }
        }

        $avgResponseTime = $totalCalls > 0 ? $totalDuration / $totalCalls : 0.0;

        return new MetricsSnapshot(
            totalCalls: $totalCalls,
            successCalls: $successCalls,
            failedCalls: $failedCalls,
            slowCalls: $slowCalls,
            notPermittedCalls: 0,
            avgResponseTime: $avgResponseTime,
            timestamp: time()
        );
    }

    public function getAllCircuitNames(): array
    {
        return array_unique(array_merge(
            array_keys($this->states),
            array_keys($this->calls)
        ));
    }

    public function deleteCircuit(string $name): void
    {
        unset($this->states[$name], $this->calls[$name], $this->locks[$name]);
    }

    public function acquireLock(string $name, string $token, int $ttl): bool
    {
        $now = time();

        // 清理过期锁
        if (isset($this->locks[$name]) && $this->locks[$name]['expireAt'] < $now) {
            unset($this->locks[$name]);
        }

        // 尝试获取锁
        if (!isset($this->locks[$name])) {
            $this->locks[$name] = [
                'token' => $token,
                'expireAt' => $now + $ttl,
            ];

            return true;
        }

        return false;
    }

    public function releaseLock(string $name, string $token): bool
    {
        if (isset($this->locks[$name]) && $this->locks[$name]['token'] === $token) {
            unset($this->locks[$name]);

            return true;
        }

        return false;
    }

    public function isAvailable(): bool
    {
        // 内存存储始终可用
        return true;
    }

    /**
     * 清理所有数据（仅用于测试）
     */
    public function clear(): void
    {
        $this->states = [];
        $this->calls = [];
        $this->locks = [];
    }
}
