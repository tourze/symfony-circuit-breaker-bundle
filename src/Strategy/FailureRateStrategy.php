<?php

namespace Tourze\Symfony\CircuitBreaker\Strategy;

use Tourze\Symfony\CircuitBreaker\Model\MetricsSnapshot;

/**
 * 失败率策略
 *
 * 基于失败率决定是否开启或关闭熔断器
 */
class FailureRateStrategy implements CircuitBreakerStrategyInterface
{
    public function shouldOpen(MetricsSnapshot $metrics, array $config): bool
    {
        $minimumCalls = $config['minimum_number_of_calls'] ?? 10;
        $failureRateThreshold = $config['failure_rate_threshold'] ?? 50;

        // 如果调用次数不足，不开启熔断器
        if ($metrics->getTotalCalls() < $minimumCalls) {
            return false;
        }

        // 如果失败率超过阈值，开启熔断器
        return $metrics->getFailureRate() >= $failureRateThreshold;
    }

    public function shouldClose(MetricsSnapshot $metrics, array $config): bool
    {
        $permittedCalls = $config['permitted_number_of_calls_in_half_open_state'] ?? 10;
        $failureRateThreshold = $config['failure_rate_threshold'] ?? 50;

        // 如果调用次数不足，不做判断
        if ($metrics->getTotalCalls() < $permittedCalls) {
            return false;
        }

        // 如果成功率足够高（失败率低于阈值），关闭熔断器
        return $metrics->getFailureRate() < $failureRateThreshold;
    }

    public function getName(): string
    {
        return 'failure_rate';
    }
}
