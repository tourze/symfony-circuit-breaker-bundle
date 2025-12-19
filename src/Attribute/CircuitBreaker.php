<?php

namespace Tourze\Symfony\CircuitBreaker\Attribute;

/**
 * CircuitBreaker属性，用于标记应用熔断器的方法
 *
 * 参考Spring的CircuitBreaker注解设计
 */
#[\Attribute(flags: \Attribute::TARGET_METHOD)]
final class CircuitBreaker
{
    /**
     * @param string        $name                                         熔断器名称
     * @param string|null   $fallbackMethod                               熔断时调用的降级方法名
     * @param int           $failureRateThreshold                         触发熔断的失败率阈值（百分比,0-100）
     * @param int           $minimumNumberOfCalls                         触发熔断的最小调用次数
     * @param int           $slidingWindowSize                            滑动窗口大小
     * @param int           $waitDurationInOpenState                      熔断器打开状态持续时间（秒）
     * @param int           $permittedNumberOfCallsInHalfOpenState        半开状态允许的调用次数
     * @param bool          $automaticTransitionFromOpenToHalfOpenEnabled 是否自动从打开状态转换到半开状态
     * @param array<string> $recordExceptions                             需要记录的异常类型
     * @param array<string> $ignoreExceptions                             需要忽略的异常类型
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $fallbackMethod = null,
        public readonly int $failureRateThreshold = 50,
        public readonly int $minimumNumberOfCalls = 10,
        public readonly int $slidingWindowSize = 100,
        public readonly int $waitDurationInOpenState = 60,
        public readonly int $permittedNumberOfCallsInHalfOpenState = 10,
        public readonly bool $automaticTransitionFromOpenToHalfOpenEnabled = true,
        public readonly array $recordExceptions = [],
        public readonly array $ignoreExceptions = [],
    ) {
    }
}
