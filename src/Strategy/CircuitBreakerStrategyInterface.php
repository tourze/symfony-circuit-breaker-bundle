<?php

namespace Tourze\Symfony\CircuitBreaker\Strategy;

use Tourze\Symfony\CircuitBreaker\Model\MetricsSnapshot;

/**
 * 熔断器策略接口
 * 
 * 定义熔断器的开启和关闭策略
 */
interface CircuitBreakerStrategyInterface
{
    /**
     * 判断是否应该打开熔断器
     *
     * @param MetricsSnapshot $metrics 当前指标快照
     * @param array<string, mixed> $config 熔断器配置
     * @return bool 是否应该打开
     */
    public function shouldOpen(MetricsSnapshot $metrics, array $config): bool;

    /**
     * 判断是否应该关闭熔断器
     *
     * @param MetricsSnapshot $metrics 当前指标快照
     * @param array<string, mixed> $config 熔断器配置
     * @return bool 是否应该关闭
     */
    public function shouldClose(MetricsSnapshot $metrics, array $config): bool;

    /**
     * 获取策略名称
     */
    public function getName(): string;
}