<?php

namespace Tourze\Symfony\CircuitBreaker\Event;

/**
 * 熔断器调用成功事件
 *
 * 当受熔断器保护的方法调用成功时触发
 */
final class CircuitSuccessEvent extends CircuitBreakerEvent
{
}
