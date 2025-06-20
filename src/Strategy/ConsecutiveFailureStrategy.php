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

    public function shouldOpen(MetricsSnapshot $metrics, array $config): bool
    {
        // 这个策略需要更复杂的实现，需要跟踪连续失败
        // 暂时使用简化版本：如果最近N次调用全部失败
        $consecutiveFailureThreshold = $config['consecutive_failure_threshold'] ?? 5;
        
        // 如果总调用次数不足，不开启
        if ($metrics->getTotalCalls() < $consecutiveFailureThreshold) {
            return false;
        }

        // 如果最近的调用全部失败（简化判断）
        // 在实际实现中，需要存储调用序列
        if ($metrics->getTotalCalls() === $metrics->getFailedCalls() && 
            $metrics->getTotalCalls() >= $consecutiveFailureThreshold) {
            return true;
        }

        return false;
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
     * 获取连续失败次数
     */
    public function getConsecutiveFailures(string $name): int
    {
        return $this->consecutiveFailures[$name] ?? 0;
    }
}