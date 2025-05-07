<?php

namespace Tourze\Symfony\CircuitBreaker\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Tourze\Symfony\CircuitBreaker\Event\CircuitFailureEvent;
use Tourze\Symfony\CircuitBreaker\Event\CircuitSuccessEvent;
use Tourze\Symfony\CircuitBreaker\Exception\CircuitOpenException;
use Tourze\Symfony\CircuitBreaker\Storage\CircuitBreakerStorageInterface;

/**
 * 熔断器服务
 * 
 * 基于Symfony组件实现的熔断器核心服务
 */
class CircuitBreakerService
{
    public function __construct(
        private readonly CircuitBreakerStorageInterface $storage,
        private readonly CircuitBreakerConfigService $configService,
        private readonly CircuitBreakerStateManager $stateManager,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * 获取指定熔断器的配置
     */
    private function getCircuitConfig(string $name): array
    {
        // 从环境变量中读取默认配置
        $defaultConfig = [
            'failure_rate_threshold' => (int)($_ENV['CIRCUIT_BREAKER_FAILURE_RATE_THRESHOLD'] ?? 50),
            'minimum_number_of_calls' => (int)($_ENV['CIRCUIT_BREAKER_MINIMUM_NUMBER_OF_CALLS'] ?? 100),
            'permitted_number_of_calls_in_half_open_state' => (int)($_ENV['CIRCUIT_BREAKER_PERMITTED_NUMBER_OF_CALLS_IN_HALF_OPEN_STATE'] ?? 10),
            'wait_duration_in_open_state' => (int)($_ENV['CIRCUIT_BREAKER_WAIT_DURATION_IN_OPEN_STATE'] ?? 60),
            'ignore_exceptions' => $this->parseExceptionsList($_ENV['CIRCUIT_BREAKER_IGNORE_EXCEPTIONS'] ?? ''),
            'record_exceptions' => $this->parseExceptionsList($_ENV['CIRCUIT_BREAKER_RECORD_EXCEPTIONS'] ?? '')
        ];
        
        // 获取特定熔断器配置
        // 由于环境变量不能很好地支持动态键值，我们可以使用特定命名约定
        // 例如：CIRCUIT_BREAKER_CIRCUIT_{NAME}_{CONFIG_KEY}
        $prefix = 'CIRCUIT_BREAKER_CIRCUIT_' . strtoupper($name) . '_';
        $specificConfig = [];
        
        foreach ($defaultConfig as $key => $value) {
            $envKey = $prefix . strtoupper($key);
            if (isset($_ENV[$envKey])) {
                if (in_array($key, ['ignore_exceptions', 'record_exceptions'])) {
                    $specificConfig[$key] = $this->parseExceptionsList($_ENV[$envKey]);
                } else {
                    $specificConfig[$key] = (int)$_ENV[$envKey];
                }
            }
        }
        
        return array_merge($defaultConfig, $specificConfig);
    }
    
    /**
     * 解析异常类列表字符串
     */
    private function parseExceptionsList(string $exceptionsList): array
    {
        if (empty($exceptionsList)) {
            return [];
        }
        
        return array_map('trim', explode(',', $exceptionsList));
    }

    /**
     * 检查熔断器是否允许请求
     */
    public function isAllowed(string $name): bool
    {
        $circuitConfig = $this->configService->getCircuitConfig($name);
        $state = $this->storage->getState($name);
        $metrics = $this->storage->getMetrics($name);

        // 如果处于关闭状态，允许请求
        if ($state->isClosed()) {
            return true;
        }

        // 如果处于开启状态
        if ($state->isOpen()) {
            $waitDuration = $circuitConfig['wait_duration_in_open_state'];
            $elapsedTime = time() - $state->getTimestamp();

            // 如果已经过了等待时间，并且启用了自动转换到半开状态
            if ($elapsedTime >= $waitDuration) {
                $this->stateManager->setHalfOpen($name);
                // 允许第一个请求通过
                return true;
            }

            // 否则拒绝请求
            $this->stateManager->incrementNotPermittedCalls($name);
            return false;
        }

        // 如果处于半开状态，检查允许的调用次数
        if ($state->isHalfOpen()) {
            $permittedCalls = $circuitConfig['permitted_number_of_calls_in_half_open_state'];
            $currentAttempts = $state->getAttemptCount();

            if ($currentAttempts < $permittedCalls) {
                $this->stateManager->incrementAttemptCount($name);
                return true;
            }

            // 超过允许的调用次数，拒绝请求
            $this->stateManager->incrementNotPermittedCalls($name);
            return false;
        }

        return false;
    }

    /**
     * 记录成功
     */
    public function recordSuccess(string $name): void
    {
        $state = $this->storage->getState($name);
        $metrics = $this->storage->getMetrics($name);
        
        $metrics->incrementCalls();
        $metrics->incrementSuccessfulCalls();
        
        // 如果处于半开状态，检查是否可以关闭熔断器
        if ($state->isHalfOpen()) {
            $circuitConfig = $this->configService->getCircuitConfig($name);
            $permittedCalls = $circuitConfig['permitted_number_of_calls_in_half_open_state'];
            $successfulCalls = $metrics->getNumberOfSuccessfulCalls();
            $totalCalls = $metrics->getNumberOfCalls();
            
            // 如果累计的成功比例足够高，关闭熔断器
            if ($totalCalls >= $permittedCalls && 
                $successfulCalls > 0 && 
                (($successfulCalls / $totalCalls) * 100) >= (100 - ($circuitConfig['failure_rate_threshold'] ?? 50))
            ) {
                $this->stateManager->setClosed($name);
                
                // 重置指标
                $metrics->reset();
            }
        }

        $this->storage->saveMetrics($name, $metrics);

        // 触发成功事件
        $this->eventDispatcher->dispatch(new CircuitSuccessEvent($name));
    }

    /**
     * 记录失败
     */
    public function recordFailure(string $name, \Throwable $throwable): void
    {
        $circuitConfig = $this->configService->getCircuitConfig($name);
        $state = $this->storage->getState($name);
        $metrics = $this->storage->getMetrics($name);
        
        $metrics->incrementCalls();
        $metrics->incrementFailedCalls();
        
        // 触发失败事件
        $this->eventDispatcher->dispatch(new CircuitFailureEvent($name, $throwable));

        // 如果在异常忽略列表中，不计入失败
        if (isset($circuitConfig['ignore_exceptions']) &&
            !empty($circuitConfig['ignore_exceptions'])
        ) {
            foreach ($circuitConfig['ignore_exceptions'] as $ignoredException) {
                if ($throwable instanceof $ignoredException) {
                    $this->logger->debug('忽略异常，不计入熔断失败: {exception}', [
                        'circuit' => $name,
                        'exception' => get_class($throwable),
                    ]);

                    // 减少失败计数
                    $metrics->incrementSuccessfulCalls();

                    $this->storage->saveMetrics($name, $metrics);
                    return;
                }
            }
        }

        // 如果在异常记录列表中或列表为空，计入失败
        $shouldRecordFailure = true;
        if (isset($circuitConfig['record_exceptions']) && 
            !empty($circuitConfig['record_exceptions'])
        ) {
            $shouldRecordFailure = false;
            foreach ($circuitConfig['record_exceptions'] as $recordedException) {
                if ($throwable instanceof $recordedException) {
                    $shouldRecordFailure = true;
                    break;
                }
            }
        }
        
        if (!$shouldRecordFailure) {
            $this->logger->debug('异常不在记录列表中，不计入熔断失败: {exception}', [
                'circuit' => $name,
                'exception' => get_class($throwable),
            ]);
            
            // 减少失败计数
            $metrics->incrementSuccessfulCalls();
            
            $this->storage->saveMetrics($name, $metrics);
            return;
        }
        
        // 如果处于半开状态，任何失败都会导致再次打开熔断器
        if ($state->isHalfOpen()) {
            $this->stateManager->setOpen($name, 100.0);
            
            $this->logger->warning('熔断器从半开状态重新打开: {circuit}', [
                'circuit' => $name,
                'exception' => get_class($throwable),
                'message' => $throwable->getMessage(),
            ]);
            
            $metrics->reset();
            $this->storage->saveMetrics($name, $metrics);
            return;
        }
        
        // 如果处于关闭状态，检查是否应该打开熔断器
        if ($state->isClosed()) {
            $failureRateThreshold = $circuitConfig['failure_rate_threshold'];
            $minimumCalls = $circuitConfig['minimum_number_of_calls'];
            $totalCalls = $metrics->getNumberOfCalls();
            $failureRate = $metrics->getFailureRate();
            
            if ($totalCalls >= $minimumCalls && $failureRate >= $failureRateThreshold) {
                $this->stateManager->setOpen($name, $failureRate);
                
                $this->logger->warning('熔断器打开: {circuit}, 失败率: {rate}%', [
                    'circuit' => $name,
                    'rate' => round($failureRate, 2),
                    'calls' => $totalCalls,
                    'failures' => $metrics->getNumberOfFailedCalls(),
                ]);
                
                $metrics->reset();
            }
        }
        
        $this->storage->saveMetrics($name, $metrics);
    }

    /**
     * 执行受熔断器保护的操作
     *
     * @param string $name 熔断器名称
     * @param callable $operation 要执行的操作
     * @param callable|null $fallback 熔断后的降级操作
     * @return mixed 操作结果
     * @throws CircuitOpenException 如果熔断器处于开启状态且没有降级处理
     * @throws \Throwable 如果操作抛出异常且没有降级处理
     */
    public function execute(string $name, callable $operation, callable $fallback = null): mixed
    {
        if (!$this->isAllowed($name)) {
            $this->logger->debug('熔断器拒绝请求: {circuit}', ['circuit' => $name]);
            
            if ($fallback !== null) {
                return $fallback();
            }
            
            throw new CircuitOpenException($name);
        }
        
        try {
            $result = $operation();
            $this->recordSuccess($name);
            return $result;
        } catch (\Throwable $throwable) {
            $this->recordFailure($name, $throwable);
            
            if ($fallback !== null) {
                return $fallback();
            }
            
            throw $throwable;
        }
    }

    /**
     * 获取熔断器状态信息
     */
    public function getCircuitInfo(string $name): array
    {
        return $this->stateManager->getCircuitInfo($name);
    }

    /**
     * 重置熔断器状态
     */
    public function resetCircuit(string $name): void
    {
        $this->stateManager->resetCircuit($name);
    }

    /**
     * 强制打开熔断器
     */
    public function forceOpen(string $name): void
    {
        $this->stateManager->forceOpen($name);
    }

    /**
     * 强制关闭熔断器
     */
    public function forceClose(string $name): void
    {
        $this->stateManager->forceClose($name);
    }
    
    /**
     * 获取当前配置
     */
    public function getConfig(): array
    {
        return $this->configService->getConfig();
    }
    
    /**
     * 获取所有熔断器名称
     * 
     * @return array<string> 熔断器名称列表
     */
    public function getAllCircuitNames(): array
    {
        return $this->stateManager->getAllCircuitNames();
    }
} 