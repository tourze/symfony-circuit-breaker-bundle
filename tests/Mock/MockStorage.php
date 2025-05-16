<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Mock;

use Tourze\Symfony\CircuitBreaker\Model\CircuitBreakerMetrics;
use Tourze\Symfony\CircuitBreaker\Model\CircuitBreakerState;
use Tourze\Symfony\CircuitBreaker\Storage\CircuitBreakerStorageInterface;

/**
 * 用于测试的内存存储实现
 */
class MockStorage implements CircuitBreakerStorageInterface
{
    /**
     * @var array<string, CircuitBreakerState>
     */
    private array $states = [];
    
    /**
     * @var array<string, CircuitBreakerMetrics>
     */
    private array $metrics = [];
    
    /**
     * 初始化存储
     */
    public function __construct()
    {
    }
    
    /**
     * 获取熔断器状态
     */
    public function getState(string $name): CircuitBreakerState
    {
        return $this->states[$name] ?? new CircuitBreakerState();
    }
    
    /**
     * 保存熔断器状态
     */
    public function saveState(string $name, CircuitBreakerState $state): void
    {
        $this->states[$name] = $state;
    }
    
    /**
     * 获取熔断器指标
     */
    public function getMetrics(string $name): CircuitBreakerMetrics
    {
        return $this->metrics[$name] ?? new CircuitBreakerMetrics();
    }
    
    /**
     * 保存熔断器指标
     */
    public function saveMetrics(string $name, CircuitBreakerMetrics $metrics): void
    {
        $this->metrics[$name] = $metrics;
    }
    
    /**
     * 获取所有已知的熔断器名称
     */
    public function getAllCircuitNames(): array
    {
        return array_unique(array_merge(array_keys($this->states), array_keys($this->metrics)));
    }
    
    /**
     * 删除熔断器数据
     */
    public function deleteCircuit(string $name): void
    {
        unset($this->states[$name], $this->metrics[$name]);
    }
    
    /**
     * 清除所有数据
     */
    public function clear(): void
    {
        $this->states = [];
        $this->metrics = [];
    }
} 