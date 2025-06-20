<?php

namespace Tourze\Symfony\CircuitBreaker\Service;

use Tourze\Symfony\CircuitBreaker\Model\CallResult;
use Tourze\Symfony\CircuitBreaker\Model\MetricsSnapshot;
use Tourze\Symfony\CircuitBreaker\Storage\CircuitBreakerStorageInterface;

/**
 * 指标收集器
 * 
 * 负责收集和统计熔断器的调用指标
 */
class MetricsCollector
{
    /**
     * @var array<string, int> 未被允许的调用计数
     */
    private array $notPermittedCalls = [];

    public function __construct(
        private readonly CircuitBreakerStorageInterface $storage,
        private readonly CircuitBreakerConfigService $configService
    ) {
    }

    /**
     * 记录成功调用
     */
    public function recordSuccess(string $name, float $duration): void
    {
        $result = new CallResult(
            success: true,
            duration: $duration,
            timestamp: time()
        );

        $this->storage->recordCall($name, $result);
    }

    /**
     * 记录失败调用
     */
    public function recordFailure(string $name, float $duration, ?\Throwable $exception = null): void
    {
        $result = new CallResult(
            success: false,
            duration: $duration,
            timestamp: time(),
            exception: $exception
        );

        $this->storage->recordCall($name, $result);
    }

    /**
     * 记录被拒绝的调用
     */
    public function recordNotPermitted(string $name): void
    {
        $this->notPermittedCalls[$name] = ($this->notPermittedCalls[$name] ?? 0) + 1;
    }

    /**
     * 获取指标快照
     */
    public function getSnapshot(string $name, int $windowSize): MetricsSnapshot
    {
        $snapshot = $this->storage->getMetricsSnapshot($name, $windowSize);
        
        // 添加未被允许的调用计数
        $notPermittedCount = $this->notPermittedCalls[$name] ?? 0;
        
        if ($notPermittedCount > 0) {
            // 创建新的快照，包含未被允许的调用计数
            return new MetricsSnapshot(
                totalCalls: $snapshot->getTotalCalls(),
                successCalls: $snapshot->getSuccessCalls(),
                failedCalls: $snapshot->getFailedCalls(),
                slowCalls: $snapshot->getSlowCalls(),
                notPermittedCalls: $notPermittedCount,
                avgResponseTime: $snapshot->getAvgResponseTime(),
                timestamp: $snapshot->getTimestamp()
            );
        }

        return $snapshot;
    }

    /**
     * 检查是否应该忽略异常
     */
    public function shouldIgnoreException(string $name, \Throwable $exception): bool
    {
        $config = $this->configService->getCircuitConfig($name);
        $ignoreExceptions = $config['ignore_exceptions'] ?? [];

        if (empty($ignoreExceptions)) {
            return false;
        }

        foreach ($ignoreExceptions as $ignoreException) {
            if ($exception instanceof $ignoreException) {
                return true;
            }
        }

        return false;
    }

    /**
     * 检查是否应该记录异常
     */
    public function shouldRecordException(string $name, \Throwable $exception): bool
    {
        $config = $this->configService->getCircuitConfig($name);
        $recordExceptions = $config['record_exceptions'] ?? [];

        // 如果列表为空，记录所有异常
        if (empty($recordExceptions)) {
            return true;
        }

        foreach ($recordExceptions as $recordException) {
            if ($exception instanceof $recordException) {
                return true;
            }
        }

        return false;
    }

    /**
     * 重置指标
     */
    public function reset(string $name): void
    {
        $this->storage->deleteCircuit($name);
        unset($this->notPermittedCalls[$name]);
    }

    /**
     * 获取未被允许的调用计数
     */
    public function getNotPermittedCalls(string $name): int
    {
        return $this->notPermittedCalls[$name] ?? 0;
    }

    /**
     * 定期清理内存中的计数器（防止内存泄漏）
     */
    public function cleanup(): void
    {
        // 清理超过1小时没有更新的计数器
        $cutoff = time() - 3600;
        
        foreach ($this->notPermittedCalls as $name => $count) {
            $snapshot = $this->storage->getMetricsSnapshot($name, 3600);
            if ($snapshot->getTimestamp() < $cutoff) {
                unset($this->notPermittedCalls[$name]);
            }
        }
    }
}