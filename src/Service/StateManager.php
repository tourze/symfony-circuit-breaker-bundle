<?php

namespace Tourze\Symfony\CircuitBreaker\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Tourze\Symfony\CircuitBreaker\Enum\CircuitState;
use Tourze\Symfony\CircuitBreaker\Event\CircuitClosedEvent;
use Tourze\Symfony\CircuitBreaker\Event\CircuitHalfOpenEvent;
use Tourze\Symfony\CircuitBreaker\Event\CircuitOpenedEvent;
use Tourze\Symfony\CircuitBreaker\Model\CircuitBreakerState;
use Tourze\Symfony\CircuitBreaker\Storage\CircuitBreakerStorageInterface;

/**
 * 熔断器状态管理器
 *
 * 负责管理和转换熔断器状态
 */
class StateManager
{
private const CACHE_TTL = 1;
    /**
     * @var array<string, CircuitBreakerState> 本地缓存
     */
    private array $localCache = [];
    /**
     * @var array<string, int> 缓存时间戳
     */
    private array $cacheTimestamps = []; // 1秒本地缓存

    public function __construct(
        private readonly CircuitBreakerStorageInterface $storage,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * 增加半开状态的尝试计数
     */
    public function incrementAttemptCount(string $name): void
    {
        $lockToken = uniqid('attempt_', true);

        if ($this->acquireLock($name, $lockToken, 5)) {
            try {
                $state = $this->storage->getState($name);
                $state->incrementAttemptCount();
                $this->storage->saveState($name, $state);

                // 清除本地缓存
                $this->clearLocalCache($name);
            } finally {
                $this->releaseLock($name, $lockToken);
            }
        }
    }

    /**
     * 获取分布式锁
     */
    private function acquireLock(string $name, string $token, int $ttl): bool
    {
        return $this->storage->acquireLock($name, $token, $ttl);
    }

    /**
     * 获取熔断器状态
     */
    public function getState(string $name): CircuitBreakerState
    {
        // 检查本地缓存
        if (isset($this->localCache[$name]) &&
            time() - ($this->cacheTimestamps[$name] ?? 0) < self::CACHE_TTL) {
            return $this->localCache[$name];
        }

        // 从存储获取
        $state = $this->storage->getState($name);

        // 更新本地缓存
        $this->localCache[$name] = $state;
        $this->cacheTimestamps[$name] = time();

        return $state;
    }

    /**
     * 清除本地缓存
     */
    private function clearLocalCache(string $name): void
    {
        unset($this->localCache[$name]);
        unset($this->cacheTimestamps[$name]);
    }

    /**
     * 释放分布式锁
     */
    private function releaseLock(string $name, string $token): bool
    {
        return $this->storage->releaseLock($name, $token);
    }

    /**
     * 重置熔断器状态
     */
    public function resetCircuit(string $name): void
    {
        $state = new CircuitBreakerState();
        $this->storage->saveState($name, $state);
        $this->clearLocalCache($name);
        
        $this->logger->info('Circuit breaker reset', [
            'circuit' => $name,
        ]);
    }

    /**
     * 强制打开熔断器
     */
    public function forceOpen(string $name): void
    {
        $this->setOpen($name);
        
        $this->logger->info('Circuit breaker force opened', [
            'circuit' => $name,
        ]);
    }

    /**
     * 设置熔断器状态为开启
     */
    public function setOpen(string $name, float $failureRate = 100.0): void
    {
        $this->transitionState($name, CircuitState::OPEN, function() use ($name, $failureRate) {
            $this->eventDispatcher->dispatch(new CircuitOpenedEvent($name, $failureRate));

            $this->logger->warning('Circuit breaker opened', [
                'circuit' => $name,
                'failure_rate' => round($failureRate, 2),
            ]);
        });
    }

    /**
     * 转换状态（带分布式锁）
     */
    private function transitionState(string $name, CircuitState $newState, ?callable $callback = null): void
    {
        $lockToken = uniqid('state_', true);

        if ($this->acquireLock($name, $lockToken, 5)) {
            try {
                $state = new CircuitBreakerState($newState);

                if ($this->storage->saveState($name, $state)) {
                    // 清除本地缓存
                    $this->clearLocalCache($name);

                    // 执行回调
                    if ($callback !== null) {
                        $callback();
                    }
                } else {
                    $this->logger->error('Failed to save circuit breaker state', [
                        'circuit' => $name,
                        'state' => $newState->value,
                    ]);
                }
            } finally {
                $this->releaseLock($name, $lockToken);
            }
        } else {
            $this->logger->warning('Failed to acquire lock for state transition', [
                'circuit' => $name,
                'state' => $newState->value,
            ]);
        }
    }

    /**
     * 强制关闭熔断器
     */
    public function forceClose(string $name): void
    {
        $this->setClosed($name);

        $this->logger->info('Circuit breaker force closed', [
            'circuit' => $name,
        ]);
    }

    /**
     * 设置熔断器状态为关闭
     */
    public function setClosed(string $name): void
    {
        $this->transitionState($name, CircuitState::CLOSED, function() use ($name) {
            $this->eventDispatcher->dispatch(new CircuitClosedEvent($name));

            $this->logger->info('Circuit breaker closed', [
                'circuit' => $name,
            ]);
        });
    }

    /**
     * 检查是否应该从开启状态转换到半开状态
     */
    public function checkForHalfOpenTransition(string $name, int $waitDuration): bool
    {
        $state = $this->getState($name);

        if (!$state->isOpen()) {
            return false;
        }

        $elapsedTime = time() - $state->getTimestamp();

        if ($elapsedTime >= $waitDuration) {
            $this->setHalfOpen($name);
            return true;
        }

        return false;
    }

    /**
     * 设置熔断器状态为半开
     */
    public function setHalfOpen(string $name): void
    {
        $this->transitionState($name, CircuitState::HALF_OPEN, function() use ($name) {
            $this->eventDispatcher->dispatch(new CircuitHalfOpenEvent($name));

            $this->logger->info('Circuit breaker transitioned to half-open', [
                'circuit' => $name,
            ]);
        });
    }
}