<?php

namespace Tourze\Symfony\CircuitBreaker\Enum;

use Tourze\EnumExtra\BadgeInterface;
use Tourze\EnumExtra\Itemable;
use Tourze\EnumExtra\ItemTrait;
use Tourze\EnumExtra\Labelable;
use Tourze\EnumExtra\Selectable;
use Tourze\EnumExtra\SelectTrait;

/**
 * 熔断器状态枚举
 */
enum CircuitState: string implements Itemable, Labelable, Selectable, BadgeInterface
{
    use ItemTrait;
    use SelectTrait;

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

    public function getLabel(): string
    {
        return match ($this) {
            self::CLOSED => '关闭',
            self::OPEN => '开启',
            self::HALF_OPEN => '半开',
        };
    }

    public function getBadge(): string
    {
        return match ($this) {
            self::CLOSED => self::SUCCESS,
            self::OPEN => self::DANGER,
            self::HALF_OPEN => self::WARNING,
        };
    }
}
