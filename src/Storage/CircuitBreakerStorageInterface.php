<?php

namespace Tourze\Symfony\CircuitBreaker\Storage;

use Tourze\Symfony\CircuitBreaker\Model\CircuitBreakerMetrics;
use Tourze\Symfony\CircuitBreaker\Model\CircuitBreakerState;

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
     */
    public function saveState(string $name, CircuitBreakerState $state): void;

    /**
     * 获取熔断器指标
     */
    public function getMetrics(string $name): CircuitBreakerMetrics;

    /**
     * 保存熔断器指标
     */
    public function saveMetrics(string $name, CircuitBreakerMetrics $metrics): void;

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
}
