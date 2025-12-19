<?php

namespace Tourze\Symfony\CircuitBreaker\Exception;

/**
 * 熔断器打开异常
 *
 * 当熔断器处于开启状态时，调用将抛出此异常
 */
final class CircuitOpenException extends \RuntimeException
{
    public function __construct(private readonly string $circuitName, string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        $message = '' !== $message ? $message : sprintf('电路熔断器 "%s" 当前处于打开状态，请求被拒绝', $circuitName);

        parent::__construct($message, $code, $previous);
    }

    /**
     * 获取熔断器名称
     */
    public function getCircuitName(): string
    {
        return $this->circuitName;
    }
}
