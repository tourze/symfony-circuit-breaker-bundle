<?php

namespace Tourze\Symfony\CircuitBreaker\Service;

use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Tourze\Symfony\CircuitBreaker\Event\CircuitFailureEvent;
use Tourze\Symfony\CircuitBreaker\Event\CircuitSuccessEvent;
use Tourze\Symfony\CircuitBreaker\Exception\CircuitOpenException;
use Tourze\Symfony\CircuitBreaker\Exception\ManualFailureException;
use Tourze\Symfony\CircuitBreaker\Strategy\ConsecutiveFailureStrategy;
use Tourze\Symfony\CircuitBreaker\Strategy\StrategyManager;

/**
 * 熔断器服务
 *
 * 基于Symfony组件实现的熔断器核心服务
 */
#[Autoconfigure]
#[WithMonologChannel(channel: 'circuit_breaker')]
final class CircuitBreakerService
{
    public function __construct(
        private readonly CircuitBreakerConfigService $configService,
        private readonly StateManager $stateManager,
        private readonly MetricsCollector $metricsCollector,
        private readonly StrategyManager $strategyManager,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * 检查熔断器是否允许请求
     */
    public function isAllowed(string $name): bool
    {
        $config = $this->configService->getCircuitConfig($name);
        $state = $this->stateManager->getState($name);

        // 如果处于关闭状态，允许请求
        if ($state->isClosed()) {
            return true;
        }

        // 如果处于开启状态
        if ($state->isOpen()) {
            // 检查是否应该转换到半开状态
            if ($this->stateManager->checkForHalfOpenTransition($name, $config['wait_duration_in_open_state'])) {
                return true;
            }

            // 否则拒绝请求
            $this->metricsCollector->recordNotPermitted($name);

            return false;
        }

        // 如果处于半开状态，检查允许的调用次数
        if ($state->isHalfOpen()) {
            $permittedCalls = $config['permitted_number_of_calls_in_half_open_state'];
            $currentAttempts = $state->getAttemptCount();

            if ($currentAttempts < $permittedCalls) {
                $this->stateManager->incrementAttemptCount($name);

                return true;
            }

            // 超过允许的调用次数，拒绝请求
            $this->metricsCollector->recordNotPermitted($name);

            return false;
        }

        return false;
    }

    /**
     * 记录成功
     */
    public function recordSuccess(string $name, float $duration = 0.0): void
    {
        $state = $this->stateManager->getState($name);
        $config = $this->configService->getCircuitConfig($name);

        // 记录成功调用
        $this->metricsCollector->recordSuccess($name, $duration);

        // 通知策略（用于 ConsecutiveFailureStrategy）
        $strategy = $this->strategyManager->getStrategyForConfig($config);
        if ($strategy instanceof ConsecutiveFailureStrategy) {
            $strategy->recordResult($name, true);
        }

        // 触发成功事件
        $this->eventDispatcher->dispatch(new CircuitSuccessEvent($name));

        // 如果处于半开状态，检查是否可以关闭熔断器
        if ($state->isHalfOpen()) {
            $metrics = $this->metricsCollector->getSnapshot($name, $config['sliding_window_size']);

            if ($strategy->shouldClose($metrics, $config)) {
                $this->stateManager->setClosed($name);
            }
        }
    }

    /**
     * 记录失败
     */
    public function recordFailure(string $name, \Throwable $throwable, float $duration = 0.0): void
    {
        $state = $this->stateManager->getState($name);
        $config = $this->configService->getCircuitConfig($name);

        // 检查是否应该忽略异常
        if ($this->metricsCollector->shouldIgnoreException($name, $throwable)) {
            $this->logger->debug('Ignoring exception for circuit breaker', [
                'circuit' => $name,
                'exception' => get_class($throwable),
            ]);
            $this->recordSuccess($name, $duration);

            return;
        }

        // 检查是否应该记录异常
        if (!$this->metricsCollector->shouldRecordException($name, $throwable)) {
            $this->logger->debug('Exception not in record list for circuit breaker', [
                'circuit' => $name,
                'exception' => get_class($throwable),
            ]);
            $this->recordSuccess($name, $duration);

            return;
        }

        // 记录失败调用
        $this->metricsCollector->recordFailure($name, $duration, $throwable);

        // 通知策略（用于 ConsecutiveFailureStrategy）
        $strategy = $this->strategyManager->getStrategyForConfig($config);
        if ($strategy instanceof ConsecutiveFailureStrategy) {
            $strategy->recordResult($name, false);
        }

        // 触发失败事件
        $this->eventDispatcher->dispatch(new CircuitFailureEvent($name, $throwable));

        // 如果处于半开状态，任何失败都会导致再次打开熔断器
        if ($state->isHalfOpen()) {
            $metrics = $this->metricsCollector->getSnapshot($name, $config['sliding_window_size']);
            $this->stateManager->setOpen($name, $metrics->getFailureRate());

            return;
        }

        // 如果处于关闭状态，检查是否应该打开熔断器
        if ($state->isClosed()) {
            $metrics = $this->metricsCollector->getSnapshot($name, $config['sliding_window_size']);

            // 设置当前熔断器名称（用于 ConsecutiveFailureStrategy）
            if ($strategy instanceof ConsecutiveFailureStrategy) {
                $strategy->setCurrentCircuitName($name);
            }

            if ($strategy->shouldOpen($metrics, $config)) {
                $this->stateManager->setOpen($name, $metrics->getFailureRate());
            }
        }
    }

    /**
     * 执行受熔断器保护的操作
     *
     * @param string    $name      熔断器名称
     * @param callable  $operation 要执行的操作
     * @param ?callable $fallback  熔断后的降级操作
     *
     * @return mixed 操作结果
     *
     * @throws CircuitOpenException 如果熔断器处于开启状态且没有降级处理
     * @throws \Throwable           如果操作抛出异常且没有降级处理
     */
    public function execute(string $name, callable $operation, ?callable $fallback = null): mixed
    {
        if (!$this->isAllowed($name)) {
            $this->logger->debug('Circuit breaker rejected request', ['circuit' => $name]);

            if (null !== $fallback) {
                return $fallback();
            }

            throw new CircuitOpenException($name);
        }

        $startTime = microtime(true);

        try {
            $result = $operation();
            $duration = (microtime(true) - $startTime) * 1000; // 转换为毫秒
            $this->recordSuccess($name, $duration);

            return $result;
        } catch (\Throwable $throwable) {
            $duration = (microtime(true) - $startTime) * 1000; // 转换为毫秒
            $this->recordFailure($name, $throwable, $duration);

            if (null !== $fallback) {
                return $fallback();
            }

            throw $throwable;
        }
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
     * 检查是否被允许（兼容旧版本）
     *
     * @deprecated 使用 isAllowed() 代替
     */
    public function isAvailable(string $name): bool
    {
        return $this->isAllowed($name);
    }

    /**
     * 标记成功（兼容旧版本）
     *
     * @deprecated 使用 recordSuccess() 代替
     */
    public function markSuccess(string $name): void
    {
        $this->recordSuccess($name);
    }

    /**
     * 标记失败（兼容旧版本）
     *
     * @deprecated 使用 recordFailure() 代替
     */
    public function markFailure(string $name): void
    {
        $this->recordFailure($name, new ManualFailureException('Manual failure mark'));
    }
}
