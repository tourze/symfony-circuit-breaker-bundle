<?php

namespace Tourze\Symfony\CircuitBreaker\Storage;

use Tourze\Symfony\CircuitBreaker\Model\CallResult;
use Tourze\Symfony\CircuitBreaker\Model\CircuitBreakerState;
use Tourze\Symfony\CircuitBreaker\Model\MetricsSnapshot;

/**
 * 熔断器存储接口
 *
 * 定义用于存储和检索熔断器状态和指标数据的接口
 */
interface CircuitBreakerStorageInterface
{
    /**
     * 获取熔断器状态
     */
    public function getState(string $name): CircuitBreakerState;

    /**
     * 保存熔断器状态
     * 
     * @return bool 是否保存成功
     */
    public function saveState(string $name, CircuitBreakerState $state): bool;

    /**
     * 记录调用结果
     */
    public function recordCall(string $name, CallResult $result): void;

    /**
     * 获取指标快照
     * 
     * @param int $windowSize 时间窗口大小（秒）
     */
    public function getMetricsSnapshot(string $name, int $windowSize): MetricsSnapshot;

    /**
     * 获取所有已知的熔断器名称
     *
     * @return array<string> 熔断器名称列表
     */
    public function getAllCircuitNames(): array;

    /**
     * 删除熔断器数据
     */
    public function deleteCircuit(string $name): void;

    /**
     * 获取分布式锁
     * 
     * @param string $name 熔断器名称
     * @param string $token 锁令牌
     * @param int $ttl 锁过期时间（秒）
     * @return bool 是否获取成功
     */
    public function acquireLock(string $name, string $token, int $ttl): bool;

    /**
     * 释放分布式锁
     * 
     * @param string $name 熔断器名称
     * @param string $token 锁令牌
     * @return bool 是否释放成功
     */
    public function releaseLock(string $name, string $token): bool;

    /**
     * 检查存储是否可用
     */
    public function isAvailable(): bool;
}
