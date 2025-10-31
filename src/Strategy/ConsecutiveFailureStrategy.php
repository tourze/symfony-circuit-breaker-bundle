<?php

namespace Tourze\Symfony\CircuitBreaker\Strategy;

use Tourze\Symfony\CircuitBreaker\Model\MetricsSnapshot;

/**
 * 连续失败策略
 *
 * 基于连续失败次数决定是否开启熔断器
 */
class ConsecutiveFailureStrategy implements CircuitBreakerStrategyInterface
{
    /**
     * @var array<string, int> 连续失败计数
     */
    private array $consecutiveFailures = [];

    /**
     * @var string|null 当前评估的熔断器名称
     */
    private ?string $currentCircuitName = null;

    public function shouldOpen(MetricsSnapshot $metrics, array $config): bool
    {
        $consecutiveFailureThreshold = $config['consecutive_failure_threshold'] ?? 5;

        // 如果没有设置当前熔断器名称，无法评估
        if (null === $this->currentCircuitName) {
            return false;
        }

        // 检查当前熔断器的连续失败次数
        $count = $this->consecutiveFailures[$this->currentCircuitName] ?? 0;

        return $count >= $consecutiveFailureThreshold;
    }

    public function shouldClose(MetricsSnapshot $metrics, array $config): bool
    {
        // 只要有成功调用就可以关闭
        return $metrics->getSuccessCalls() > 0;
    }

    public function getName(): string
    {
        return 'consecutive_failure';
    }

    /**
     * 记录调用结果（用于跟踪连续失败）
     */
    public function recordResult(string $name, bool $success): void
    {
        if ($success) {
            // 成功时重置计数器
            $this->consecutiveFailures[$name] = 0;
        } else {
            // 失败时增加计数器
            $this->consecutiveFailures[$name] = ($this->consecutiveFailures[$name] ?? 0) + 1;
        }
    }

    /**
     * 设置当前评估的熔断器名称
     */
    public function setCurrentCircuitName(string $name): void
    {
        $this->currentCircuitName = $name;
    }

    /**
     * 获取连续失败次数
     */
    public function getConsecutiveFailures(string $name): int
    {
        return $this->consecutiveFailures[$name] ?? 0;
    }

    /**
     * 重置所有计数器
     */
    public function reset(): void
    {
        $this->consecutiveFailures = [];
    }
}
