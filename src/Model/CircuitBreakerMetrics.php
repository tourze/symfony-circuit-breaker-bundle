<?php

namespace Tourze\Symfony\CircuitBreaker\Model;

/**
 * 熔断器统计指标
 */
class CircuitBreakerMetrics
{
    /**
     * 总调用次数
     */
    private int $numberOfCalls = 0;

    /**
     * 成功调用次数
     */
    private int $numberOfSuccessfulCalls = 0;

    /**
     * 失败调用次数
     */
    private int $numberOfFailedCalls = 0;

    /**
     * 最近未使用的时间戳
     */
    private ?int $notPermittedCalls = 0;

    /**
     * 增加调用次数
     */
    public function incrementCalls(): void
    {
        $this->numberOfCalls++;
    }

    /**
     * 增加成功调用次数
     */
    public function incrementSuccessfulCalls(): void
    {
        $this->numberOfSuccessfulCalls++;
    }

    /**
     * 增加失败调用次数
     */
    public function incrementFailedCalls(): void
    {
        $this->numberOfFailedCalls++;
    }

    /**
     * 增加被拒绝调用次数
     */
    public function incrementNotPermittedCalls(): void
    {
        $this->notPermittedCalls++;
    }

    /**
     * 重置统计
     */
    public function reset(): void
    {
        $this->numberOfCalls = 0;
        $this->numberOfSuccessfulCalls = 0;
        $this->numberOfFailedCalls = 0;
        $this->notPermittedCalls = 0;
    }

    /**
     * 获取总调用次数
     */
    public function getNumberOfCalls(): int
    {
        return $this->numberOfCalls;
    }

    /**
     * 获取成功调用次数
     */
    public function getNumberOfSuccessfulCalls(): int
    {
        return $this->numberOfSuccessfulCalls;
    }

    /**
     * 获取失败调用次数
     */
    public function getNumberOfFailedCalls(): int
    {
        return $this->numberOfFailedCalls;
    }

    /**
     * 获取被拒绝调用次数
     */
    public function getNotPermittedCalls(): int
    {
        return $this->notPermittedCalls;
    }

    /**
     * 获取失败率
     */
    public function getFailureRate(): float
    {
        if ($this->numberOfCalls === 0) {
            return 0.0;
        }

        return ($this->numberOfFailedCalls / $this->numberOfCalls) * 100;
    }

    /**
     * 创建数组表示
     */
    public function toArray(): array
    {
        return [
            'numberOfCalls' => $this->numberOfCalls,
            'numberOfSuccessfulCalls' => $this->numberOfSuccessfulCalls,
            'numberOfFailedCalls' => $this->numberOfFailedCalls,
            'notPermittedCalls' => $this->notPermittedCalls,
            'failureRate' => $this->getFailureRate(),
        ];
    }
}
