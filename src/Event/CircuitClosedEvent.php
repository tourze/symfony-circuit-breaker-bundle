<?php

namespace Tourze\Symfony\CircuitBreaker\Event;

/**
 * 熔断器关闭事件
 *
 * 当熔断器从半开状态转为关闭状态时触发
 */
final class CircuitClosedEvent extends CircuitBreakerEvent
{
}
