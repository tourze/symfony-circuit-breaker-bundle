<?php

namespace Tourze\Symfony\CircuitBreaker\Model;

/**
 * 调用结果
 * 
 * 记录单次调用的结果信息
 */
final class CallResult
{
    /**
     * @param bool $success 是否成功
     * @param float $duration 调用耗时（毫秒）
     * @param int $timestamp 调用时间戳
     * @param \Throwable|null $exception 异常信息
     */
    public function __construct(
        private readonly bool $success,
        private readonly float $duration,
        private readonly int $timestamp,
        private readonly ?\Throwable $exception = null
    ) {
    }

    /**
     * 从字符串创建
     */
    public static function fromString(string $data, int $timestamp): self
    {
        [$type, $duration] = explode(':', $data);

        return new self(
            success: $type === 'success',
            duration: (float) $duration,
            timestamp: $timestamp
        );
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getDuration(): float
    {
        return $this->duration;
    }

    public function getTimestamp(): int
    {
        return $this->timestamp;
    }

    public function getException(): ?\Throwable
    {
        return $this->exception;
    }

    /**
     * 判断是否为慢调用
     *
     * @param float $slowCallThreshold 慢调用阈值（毫秒）
     */
    public function isSlowCall(float $slowCallThreshold): bool
    {
        return $this->duration > $slowCallThreshold;
    }

    /**
     * 转换为字符串（用于存储）
     */
    public function toString(): string
    {
        $type = $this->success ? 'success' : 'failure';
        return sprintf('%s:%.2f', $type, $this->duration);
    }
}