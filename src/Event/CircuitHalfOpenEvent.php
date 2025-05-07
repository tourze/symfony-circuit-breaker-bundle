<?php

namespace Tourze\Symfony\CircuitBreaker\Event;

/**
 * 熔断器半开事件
 *
 * 当熔断器从打开状态转为半开状态时触发
 */
final class CircuitHalfOpenEvent extends CircuitBreakerEvent
{
}
