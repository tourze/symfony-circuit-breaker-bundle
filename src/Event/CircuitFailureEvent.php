<?php

namespace Tourze\Symfony\CircuitBreaker\Event;

/**
 * 熔断器调用失败事件
 *
 * 当受熔断器保护的方法调用失败时触发
 */
final class CircuitFailureEvent extends CircuitBreakerEvent
{
    /**
     * @param string     $circuitName 熔断器名称
     * @param \Throwable $throwable   抛出的异常
     */
    public function __construct(
        string $circuitName,
        private readonly \Throwable $throwable,
    ) {
        parent::__construct($circuitName);
    }

    /**
     * 获取抛出的异常
     */
    public function getThrowable(): \Throwable
    {
        return $this->throwable;
    }
}
