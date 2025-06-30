<?php

namespace Tourze\Symfony\CircuitBreaker\Strategy;

use Tourze\Symfony\CircuitBreaker\Model\MetricsSnapshot;

/**
 * 慢调用策略
 *
 * 基于慢调用率决定是否开启或关闭熔断器
 */
class SlowCallStrategy implements CircuitBreakerStrategyInterface
{
    public function shouldOpen(MetricsSnapshot $metrics, array $config): bool
    {
        $minimumCalls = $config['minimum_number_of_calls'] ?? 10;
        $slowCallRateThreshold = $config['slow_call_rate_threshold'] ?? 50;

        // 如果调用次数不足，不开启熔断器
        if ($metrics->getTotalCalls() < $minimumCalls) {
            return false;
        }

        // 如果慢调用率超过阈值，开启熔断器
        return $metrics->getSlowCallRate() >= $slowCallRateThreshold;
    }

    public function shouldClose(MetricsSnapshot $metrics, array $config): bool
    {
        $permittedCalls = $config['permitted_number_of_calls_in_half_open_state'] ?? 10;
        $slowCallRateThreshold = $config['slow_call_rate_threshold'] ?? 50;

        // 如果调用次数不足，不做判断
        if ($metrics->getTotalCalls() < $permittedCalls) {
            return false;
        }

        // 如果慢调用率低于阈值，关闭熔断器
        return $metrics->getSlowCallRate() < $slowCallRateThreshold;
    }

    public function getName(): string
    {
        return 'slow_call';
    }
}