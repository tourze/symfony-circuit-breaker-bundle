<?php

namespace Tourze\Symfony\CircuitBreaker\Enum;

/**
 * 熔断器状态枚举
 */
enum CircuitState: string
{
    /**
     * 关闭状态 - 允许请求通过
     */
    case CLOSED = 'closed';

    /**
     * 开启状态 - 请求被拒绝
     */
    case OPEN = 'open';

    /**
     * 半开状态 - 允许有限请求通过，用于测试服务是否恢复
     */
    case HALF_OPEN = 'half_open';
}
