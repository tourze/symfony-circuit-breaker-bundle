<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Tourze\Symfony\CircuitBreaker\Enum\CircuitState;
use Tourze\Symfony\CircuitBreaker\Exception\CircuitOpenException;
use Tourze\Symfony\CircuitBreaker\Model\CallResult;
use Tourze\Symfony\CircuitBreaker\Model\CircuitBreakerState;
use Tourze\Symfony\CircuitBreaker\Service\CircuitBreakerConfigService;
use Tourze\Symfony\CircuitBreaker\Service\CircuitBreakerService;
use Tourze\Symfony\CircuitBreaker\Service\MetricsCollector;
use Tourze\Symfony\CircuitBreaker\Service\StateManager;
use Tourze\Symfony\CircuitBreaker\Storage\MemoryStorage;
use Tourze\Symfony\CircuitBreaker\Strategy\StrategyManager;

class CircuitBreakerServiceTest extends TestCase
{
    private MemoryStorage $storage;
    private CircuitBreakerConfigService $configService;
    private StateManager $stateManager;
    private MetricsCollector $metricsCollector;
    private StrategyManager $strategyManager;
    private EventDispatcher $eventDispatcher;
    private CircuitBreakerService $circuitBreakerService;
    
    protected function setUp(): void
    {
        $this->storage = new MemoryStorage();
        $this->configService = $this->createMock(CircuitBreakerConfigService::class);
        $this->eventDispatcher = new EventDispatcher();
        $logger = new NullLogger();
        
        // 配置mock
        $this->configService->method('getCircuitConfig')
            ->willReturn([
                'failure_rate_threshold' => 50,
                'minimum_number_of_calls' => 10,
                'permitted_number_of_calls_in_half_open_state' => 5,
                'wait_duration_in_open_state' => 60,
                'sliding_window_size' => 100,
                'slow_call_duration_threshold' => 1000,
                'slow_call_rate_threshold' => 50,
                'consecutive_failure_threshold' => 5,
                'ignore_exceptions' => [],
                'record_exceptions' => []
            ]);
            
        $this->stateManager = new StateManager(
            $this->storage,
            $this->eventDispatcher,
            $logger
        );
        
        $this->metricsCollector = new MetricsCollector(
            $this->storage,
            $this->configService
        );
        
        $this->strategyManager = new StrategyManager($logger);
        
        $this->circuitBreakerService = new CircuitBreakerService(
            $this->configService,
            $this->stateManager,
            $this->metricsCollector,
            $this->strategyManager,
            $this->eventDispatcher,
            $logger
        );
    }
    
    protected function tearDown(): void
    {
        $this->storage->clear();
    }
    
    public function testIsAllowed_whenClosed_returnsTrue(): void
    {
        $state = new CircuitBreakerState(CircuitState::CLOSED);
        $this->storage->saveState('service1', $state);
        
        $result = $this->circuitBreakerService->isAllowed('service1');
        
        $this->assertTrue($result);
    }
    
    public function testIsAllowed_whenOpen_returnsFalse(): void
    {
        $state = new CircuitBreakerState(CircuitState::OPEN);
        $this->storage->saveState('service1', $state);
        
        $result = $this->circuitBreakerService->isAllowed('service1');
        
        $this->assertFalse($result);
    }
    
    public function testIsAllowed_whenOpenAndWaitDurationPassed_returnsTrue(): void
    {
        // 创建一个已经开启并超过等待时间的状态
        $state = new CircuitBreakerState(CircuitState::OPEN, time() - 100);
        $this->storage->saveState('service1', $state);
        
        $result = $this->circuitBreakerService->isAllowed('service1');
        
        $this->assertTrue($result);
        
        // 验证状态已经改变为半开
        $updatedState = $this->stateManager->getState('service1');
        $this->assertEquals(CircuitState::HALF_OPEN, $updatedState->getState());
    }
    
    public function testIsAllowed_whenHalfOpenWithinAttemptLimit_returnsTrue(): void
    {
        $state = new CircuitBreakerState(CircuitState::HALF_OPEN);
        $this->storage->saveState('service1', $state);
        
        // Record some calls but less than the limit
        for ($i = 0; $i < 3; $i++) {
            $this->storage->recordCall('service1', new CallResult(true, 100.0, time()));
        }
        
        $result = $this->circuitBreakerService->isAllowed('service1');
        
        $this->assertTrue($result);
    }
    
    public function testIsAllowed_whenHalfOpenExceedingAttemptLimit_returnsFalse(): void
    {
        $state = new CircuitBreakerState(CircuitState::HALF_OPEN);
        $this->storage->saveState('service1', $state);
        
        // Call isAllowed up to the limit to increment attempt count
        for ($i = 0; $i < 5; $i++) {
            $this->circuitBreakerService->isAllowed('service1');
        }
        
        // The next call should be rejected
        $result = $this->circuitBreakerService->isAllowed('service1');
        
        $this->assertFalse($result);
    }
    
    public function testRecordSuccess_increasesSuccessCounter(): void
    {
        $this->circuitBreakerService->recordSuccess('service1', 100.0);
        
        $metrics = $this->storage->getMetricsSnapshot('service1', 100);
        $this->assertEquals(1, $metrics->getTotalCalls());
        $this->assertEquals(1, $metrics->getSuccessCalls());
    }
    
    public function testRecordSuccess_whenHalfOpen_andEnoughSuccesses_closeCircuit(): void
    {
        // 准备一个处于半开状态的熔断器
        $state = new CircuitBreakerState(CircuitState::HALF_OPEN);
        $this->storage->saveState('service1', $state);
        
        // 记录足够的成功调用
        for ($i = 0; $i < 10; $i++) {
            $this->circuitBreakerService->recordSuccess('service1', 100.0);
        }
        
        // 验证状态已经改变为关闭
        $updatedState = $this->stateManager->getState('service1');
        $this->assertEquals(CircuitState::CLOSED, $updatedState->getState());
    }
    
    public function testRecordFailure_increasesFailureCounter(): void
    {
        $this->circuitBreakerService->recordFailure('service1', new \RuntimeException('测试异常'), 100.0);
        
        $metrics = $this->storage->getMetricsSnapshot('service1', 100);
        $this->assertEquals(1, $metrics->getTotalCalls());
        $this->assertEquals(1, $metrics->getFailedCalls());
    }
    
    public function testRecordFailure_withIgnoredException_doesntCountAsFailure(): void
    {
        // 创建一个新的配置服务 mock，设置忽略 InvalidArgumentException
        $configService = $this->createMock(CircuitBreakerConfigService::class);
        $configService->method('getCircuitConfig')
            ->willReturn([
                'failure_rate_threshold' => 50,
                'minimum_number_of_calls' => 10,
                'permitted_number_of_calls_in_half_open_state' => 5,
                'wait_duration_in_open_state' => 60,
                'sliding_window_size' => 100,
                'slow_call_duration_threshold' => 1000,
                'slow_call_rate_threshold' => 50,
                'consecutive_failure_threshold' => 5,
                'ignore_exceptions' => [\InvalidArgumentException::class],
                'record_exceptions' => []
            ]);
        
        $logger = new NullLogger();
        $metricsCollector = new MetricsCollector($this->storage, $configService);
        
        // 创建新的熔断器服务
        $service = new CircuitBreakerService(
            $configService,
            $this->stateManager,
            $metricsCollector,
            $this->strategyManager,
            $this->eventDispatcher,
            $logger
        );
        
        // 使用应该被忽略的异常记录失败
        $service->recordFailure('test.service', new \InvalidArgumentException('忽略的异常'), 100.0);
        
        // 获取更新后的指标
        $metrics = $this->storage->getMetricsSnapshot('test.service', 100);
        
        // 验证 - 忽略的异常不计入失败
        $this->assertEquals(1, $metrics->getTotalCalls());
        $this->assertEquals(0, $metrics->getFailedCalls());
        $this->assertEquals(1, $metrics->getSuccessCalls());
    }
    
    public function testRecordFailure_whenHalfOpen_opensCircuit(): void
    {
        // 准备一个处于半开状态的熔断器
        $state = new CircuitBreakerState(CircuitState::HALF_OPEN);
        $this->storage->saveState('service1', $state);
        
        // 记录一次失败，应该触发打开熔断器
        $this->circuitBreakerService->recordFailure('service1', new \RuntimeException('测试异常'), 100.0);
        
        $updatedState = $this->stateManager->getState('service1');
        $this->assertEquals(CircuitState::OPEN, $updatedState->getState());
    }
    
    public function testRecordFailure_whenClosedAndThresholdReached_opensCircuit(): void
    {
        // 准备一个关闭状态的熔断器
        $state = new CircuitBreakerState(CircuitState::CLOSED);
        $this->storage->saveState('service1', $state);
        
        // 准备足够的调用达到最小调用数
        for ($i = 0; $i < 9; $i++) {
            $this->circuitBreakerService->recordSuccess('service1', 100.0);
        }
        
        // 记录足够的失败使失败率超过阈值
        for ($i = 0; $i < 11; $i++) {
            $this->circuitBreakerService->recordFailure('service1', new \RuntimeException('测试异常'), 100.0);
        }
        
        $updatedState = $this->stateManager->getState('service1');
        $this->assertEquals(CircuitState::OPEN, $updatedState->getState());
    }
    
    public function testExecute_whenCircuitClosed_executesOperation(): void
    {
        $state = new CircuitBreakerState(CircuitState::CLOSED);
        $this->storage->saveState('service1', $state);
        
        $result = $this->circuitBreakerService->execute(
            'service1',
            function() {
                return 'success';
            },
            function() {
                return 'fallback';
            }
        );
        
        $this->assertEquals('success', $result);
    }
    
    public function testExecute_whenCircuitOpen_executesFallback(): void
    {
        $state = new CircuitBreakerState(CircuitState::OPEN);
        $this->storage->saveState('service1', $state);
        
        $result = $this->circuitBreakerService->execute(
            'service1',
            function() {
                return 'success';
            },
            function() {
                return 'fallback';
            }
        );
        
        $this->assertEquals('fallback', $result);
    }
    
    public function testExecute_whenOperationThrows_recordsFailureAndRethrows(): void
    {
        $state = new CircuitBreakerState(CircuitState::CLOSED);
        $this->storage->saveState('service1', $state);
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Operation failed');
        
        try {
            $this->circuitBreakerService->execute(
                'service1',
                function() {
                    throw new \RuntimeException('Operation failed');
                }
            );
        } finally {
            $metrics = $this->storage->getMetricsSnapshot('service1', 100);
            $this->assertEquals(1, $metrics->getTotalCalls());
            $this->assertEquals(1, $metrics->getFailedCalls());
        }
    }
    
    public function testExecute_whenOperationSucceeds_recordsSuccess(): void
    {
        $state = new CircuitBreakerState(CircuitState::CLOSED);
        $this->storage->saveState('service1', $state);
        
        $this->circuitBreakerService->execute(
            'service1',
            function() {
                return 'success';
            }
        );
        
        $metrics = $this->storage->getMetricsSnapshot('service1', 100);
        $this->assertEquals(1, $metrics->getTotalCalls());
        $this->assertEquals(1, $metrics->getSuccessCalls());
    }
    
    public function testExecute_whenCircuitOpenAndNoFallback_throwsCircuitOpenException(): void
    {
        $state = new CircuitBreakerState(CircuitState::OPEN);
        $this->storage->saveState('service1', $state);
        
        $this->expectException(CircuitOpenException::class);
        
        $this->circuitBreakerService->execute(
            'service1',
            function() {
                return 'success';
            }
        );
    }
    
    public function testResetCircuit_resetsStateAndMetrics(): void
    {
        // 设置一些初始状态
        $state = new CircuitBreakerState(CircuitState::OPEN);
        $this->storage->saveState('service1', $state);
        
        // 添加一些调用记录
        $this->storage->recordCall('service1', new CallResult(false, 100.0, time()));
        
        // 重置熔断器
        $this->circuitBreakerService->resetCircuit('service1');
        
        // 验证状态已重置
        $resetState = $this->stateManager->getState('service1');
        $resetMetrics = $this->storage->getMetricsSnapshot('service1', 100);
        
        $this->assertEquals(CircuitState::CLOSED, $resetState->getState());
        $this->assertEquals(0, $resetMetrics->getTotalCalls());
        $this->assertEquals(0, $resetMetrics->getFailedCalls());
    }
    
    public function testForceOpen_setsStateToOpen(): void
    {
        $state = new CircuitBreakerState(CircuitState::CLOSED);
        $this->storage->saveState('service1', $state);
        
        $this->circuitBreakerService->forceOpen('service1');
        
        $updatedState = $this->stateManager->getState('service1');
        $this->assertEquals(CircuitState::OPEN, $updatedState->getState());
    }
    
    public function testForceClose_setsStateToClosed(): void
    {
        $state = new CircuitBreakerState(CircuitState::OPEN);
        $this->storage->saveState('service1', $state);
        
        $this->circuitBreakerService->forceClose('service1');
        
        $updatedState = $this->stateManager->getState('service1');
        $this->assertEquals(CircuitState::CLOSED, $updatedState->getState());
    }
}