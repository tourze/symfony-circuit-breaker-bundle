<?php

namespace Tourze\Symfony\CircuitBreaker\Model;

/**
 * 指标快照
 * 
 * 表示某个时间点的熔断器指标数据
 */
final class MetricsSnapshot
{
    /**
     * @param int $totalCalls 总调用次数
     * @param int $successCalls 成功调用次数
     * @param int $failedCalls 失败调用次数
     * @param int $slowCalls 慢调用次数
     * @param int $notPermittedCalls 被拒绝的调用次数
     * @param float $avgResponseTime 平均响应时间（毫秒）
     * @param int $timestamp 快照时间戳
     */
    public function __construct(
        private readonly int $totalCalls = 0,
        private readonly int $successCalls = 0,
        private readonly int $failedCalls = 0,
        private readonly int $slowCalls = 0,
        private readonly int $notPermittedCalls = 0,
        private readonly float $avgResponseTime = 0.0,
        private readonly int $timestamp = 0
    ) {
    }

    /**
     * 从数组创建
     */
    public static function fromArray(array $data): self
    {
        return new self(
            totalCalls: $data['total_calls'] ?? 0,
            successCalls: $data['success_calls'] ?? 0,
            failedCalls: $data['failed_calls'] ?? 0,
            slowCalls: $data['slow_calls'] ?? 0,
            notPermittedCalls: $data['not_permitted_calls'] ?? 0,
            avgResponseTime: $data['avg_response_time'] ?? 0.0,
            timestamp: $data['timestamp'] ?? time()
        );
    }

    public function getTotalCalls(): int
    {
        return $this->totalCalls;
    }

    public function getSuccessCalls(): int
    {
        return $this->successCalls;
    }

    public function getFailedCalls(): int
    {
        return $this->failedCalls;
    }

    public function getSlowCalls(): int
    {
        return $this->slowCalls;
    }

    public function getNotPermittedCalls(): int
    {
        return $this->notPermittedCalls;
    }

    public function getAvgResponseTime(): float
    {
        return $this->avgResponseTime;
    }

    public function getTimestamp(): int
    {
        return $this->timestamp;
    }

    /**
     * 转换为数组
     */
    public function toArray(): array
    {
        return [
            'total_calls' => $this->totalCalls,
            'success_calls' => $this->successCalls,
            'failed_calls' => $this->failedCalls,
            'slow_calls' => $this->slowCalls,
            'not_permitted_calls' => $this->notPermittedCalls,
            'failure_rate' => round($this->getFailureRate(), 2),
            'success_rate' => round($this->getSuccessRate(), 2),
            'slow_call_rate' => round($this->getSlowCallRate(), 2),
            'avg_response_time' => round($this->avgResponseTime, 2),
            'timestamp' => $this->timestamp,
        ];
    }

    /**
     * 获取失败率（百分比）
     */
    public function getFailureRate(): float
    {
        if ($this->totalCalls === 0) {
            return 0.0;
        }

        return ($this->failedCalls / $this->totalCalls) * 100;
    }

    /**
     * 获取成功率（百分比）
     */
    public function getSuccessRate(): float
    {
        if ($this->totalCalls === 0) {
            return 100.0;
        }

        return ($this->successCalls / $this->totalCalls) * 100;
    }

    /**
     * 获取慢调用率（百分比）
     */
    public function getSlowCallRate(): float
    {
        if ($this->totalCalls === 0) {
            return 0.0;
        }

        return ($this->slowCalls / $this->totalCalls) * 100;
    }
}