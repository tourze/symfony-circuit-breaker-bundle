<?php

namespace Tourze\Symfony\CircuitBreaker\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * 熔断器事件基类
 */
abstract class CircuitBreakerEvent extends Event
{
    /**
     * @param string $circuitName 熔断器名称
     */
    public function __construct(
        private readonly string $circuitName,
    ) {
    }

    /**
     * 获取熔断器名称
     */
    public function getCircuitName(): string
    {
        return $this->circuitName;
    }
}
