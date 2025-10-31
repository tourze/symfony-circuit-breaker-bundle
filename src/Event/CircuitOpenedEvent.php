<?php

namespace Tourze\Symfony\CircuitBreaker\Event;

/**
 * 熔断器打开事件
 *
 * 当熔断器从关闭状态转为打开状态时触发
 */
final class CircuitOpenedEvent extends CircuitBreakerEvent
{
    /**
     * @param string $circuitName 熔断器名称
     * @param float  $failureRate 失败率
     */
    public function __construct(
        string $circuitName,
        private readonly float $failureRate,
    ) {
        parent::__construct($circuitName);
    }

    /**
     * 获取触发熔断的失败率
     */
    public function getFailureRate(): float
    {
        return $this->failureRate;
    }
}
