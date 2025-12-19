<?php

namespace Tourze\Symfony\CircuitBreaker\Model;

use Tourze\Symfony\CircuitBreaker\Enum\CircuitState;

/**
 * 熔断器状态信息
 */
final class CircuitBreakerState
{
    /**
     * @param CircuitState $state        当前状态
     * @param int          $timestamp    状态创建时间戳
     * @param int          $attemptCount 半开状态的尝试计数
     */
    public function __construct(
        private CircuitState $state = CircuitState::CLOSED,
        private int $timestamp = 0,
        private int $attemptCount = 0,
    ) {
        $this->timestamp = 0 === $timestamp ? time() : $timestamp;
    }

    /**
     * 获取当前状态
     */
    public function getState(): CircuitState
    {
        return $this->state;
    }

    /**
     * 设置状态
     */
    public function setState(CircuitState $state): void
    {
        $this->state = $state;
        $this->timestamp = time();

        if (CircuitState::HALF_OPEN === $state) {
            $this->attemptCount = 0;
        }
    }

    /**
     * 获取状态创建时间戳
     */
    public function getTimestamp(): int
    {
        return $this->timestamp;
    }

    /**
     * 增加半开状态尝试计数
     */
    public function incrementAttemptCount(): void
    {
        ++$this->attemptCount;
    }

    /**
     * 获取半开状态尝试计数
     */
    public function getAttemptCount(): int
    {
        return $this->attemptCount;
    }

    /**
     * 检查是否处于关闭状态
     */
    public function isClosed(): bool
    {
        return CircuitState::CLOSED === $this->state;
    }

    /**
     * 检查是否处于开启状态
     */
    public function isOpen(): bool
    {
        return CircuitState::OPEN === $this->state;
    }

    /**
     * 检查是否处于半开状态
     */
    public function isHalfOpen(): bool
    {
        return CircuitState::HALF_OPEN === $this->state;
    }

    /**
     * 转换为数组
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'timestamp' => $this->timestamp,
            'attemptCount' => $this->attemptCount,
        ];
    }

    /**
     * 从数组创建
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $state = CircuitState::from($data['state'] ?? CircuitState::CLOSED->value);
        $timestamp = $data['timestamp'] ?? time();
        $attemptCount = $data['attemptCount'] ?? 0;

        return new self($state, $timestamp, $attemptCount);
    }
}
