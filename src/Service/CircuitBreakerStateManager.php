<?php

namespace Tourze\Symfony\CircuitBreaker\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Tourze\Symfony\CircuitBreaker\Enum\CircuitState;
use Tourze\Symfony\CircuitBreaker\Event\CircuitClosedEvent;
use Tourze\Symfony\CircuitBreaker\Event\CircuitHalfOpenEvent;
use Tourze\Symfony\CircuitBreaker\Event\CircuitOpenedEvent;
use Tourze\Symfony\CircuitBreaker\Model\CircuitBreakerMetrics;
use Tourze\Symfony\CircuitBreaker\Model\CircuitBreakerState;
use Tourze\Symfony\CircuitBreaker\Storage\CircuitBreakerStorageInterface;

/**
 * 熔断器状态管理服务
 */
class CircuitBreakerStateManager
{
    public function __construct(
        private readonly CircuitBreakerStorageInterface $storage,
        private readonly CircuitBreakerConfigService $configService,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }
    
    /**
     * 获取熔断器状态
     */
    public function getState(string $name): CircuitBreakerState
    {
        return $this->storage->getState($name);
    }
    
    /**
     * 获取熔断器指标
     */
    public function getMetrics(string $name): CircuitBreakerMetrics
    {
        return $this->storage->getMetrics($name);
    }
    
    /**
     * 设置熔断器状态为半开
     */
    public function setHalfOpen(string $name): void
    {
        $state = $this->storage->getState($name);
        $state->setState(CircuitState::HALF_OPEN);
        $this->storage->saveState($name, $state);
        
        // 触发事件
        $this->eventDispatcher->dispatch(new CircuitHalfOpenEvent($name));
        
        $this->logger->info('熔断器转为半开状态: {circuit}', ['circuit' => $name]);
    }
    
    /**
     * 设置熔断器状态为打开
     */
    public function setOpen(string $name, float $failureRate = 100.0): void
    {
        $state = $this->storage->getState($name);
        $state->setState(CircuitState::OPEN);
        $this->storage->saveState($name, $state);
        
        // 触发事件
        $this->eventDispatcher->dispatch(new CircuitOpenedEvent($name, $failureRate));
        
        $this->logger->warning('熔断器打开: {circuit}, 失败率: {rate}%', [
            'circuit' => $name,
            'rate' => round($failureRate, 2),
        ]);
    }
    
    /**
     * 设置熔断器状态为关闭
     */
    public function setClosed(string $name): void
    {
        $state = $this->storage->getState($name);
        $state->setState(CircuitState::CLOSED);
        $this->storage->saveState($name, $state);
        
        // 触发事件
        $this->eventDispatcher->dispatch(new CircuitClosedEvent($name));
        
        $this->logger->info('熔断器关闭: {circuit}', ['circuit' => $name]);
    }
    
    /**
     * 增加尝试计数
     */
    public function incrementAttemptCount(string $name): void
    {
        $state = $this->storage->getState($name);
        $state->incrementAttemptCount();
        $this->storage->saveState($name, $state);
    }
    
    /**
     * 增加不被允许的调用计数
     */
    public function incrementNotPermittedCalls(string $name): void
    {
        $metrics = $this->storage->getMetrics($name);
        $metrics->incrementNotPermittedCalls();
        $this->storage->saveMetrics($name, $metrics);
    }
    
    /**
     * 重置熔断器状态
     */
    public function resetCircuit(string $name): void
    {
        $state = new CircuitBreakerState();
        $metrics = new CircuitBreakerMetrics();
        
        $this->storage->saveState($name, $state);
        $this->storage->saveMetrics($name, $metrics);
        
        $this->logger->info('熔断器已重置: {circuit}', ['circuit' => $name]);
    }
    
    /**
     * 强制打开熔断器
     */
    public function forceOpen(string $name): void
    {
        $this->setOpen($name);
        $this->logger->info('熔断器已强制打开: {circuit}', ['circuit' => $name]);
    }
    
    /**
     * 强制关闭熔断器
     */
    public function forceClose(string $name): void
    {
        $this->setClosed($name);
        $this->logger->info('熔断器已强制关闭: {circuit}', ['circuit' => $name]);
    }
    
    /**
     * 获取所有熔断器名称
     */
    public function getAllCircuitNames(): array
    {
        return $this->storage->getAllCircuitNames();
    }
    
    /**
     * 获取熔断器状态信息
     */
    public function getCircuitInfo(string $name): array
    {
        $state = $this->storage->getState($name);
        $metrics = $this->storage->getMetrics($name);
        $config = $this->configService->getCircuitConfig($name);
        
        return [
            'name' => $name,
            'state' => $state->getState()->value,
            'timestamp' => $state->getTimestamp(),
            'metrics' => $metrics->toArray(),
            'config' => $config,
        ];
    }
} 