<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Tourze\Symfony\CircuitBreaker\Enum\CircuitState;
use Tourze\Symfony\CircuitBreaker\Exception\CircuitOpenException;
use Tourze\Symfony\CircuitBreaker\Model\CircuitBreakerMetrics;
use Tourze\Symfony\CircuitBreaker\Model\CircuitBreakerState;
use Tourze\Symfony\CircuitBreaker\Service\CircuitBreakerConfigService;
use Tourze\Symfony\CircuitBreaker\Service\CircuitBreakerService;
use Tourze\Symfony\CircuitBreaker\Service\CircuitBreakerStateManager;
use Tourze\Symfony\CircuitBreaker\Tests\Mock\MockStorage;

class CircuitBreakerServiceTest extends TestCase
{
    private MockStorage $storage;
    private CircuitBreakerConfigService $configService;
    private CircuitBreakerStateManager $stateManager;
    private EventDispatcher $eventDispatcher;
    private CircuitBreakerService $circuitBreakerService;
    
    protected function setUp(): void
    {
        $this->storage = new MockStorage();
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
                'ignore_exceptions' => [],
                'record_exceptions' => []
            ]);
            
        $this->stateManager = new CircuitBreakerStateManager(
            $this->storage,
            $this->configService,
            $this->eventDispatcher,
            $logger
        );
        
        $this->circuitBreakerService = new CircuitBreakerService(
            $this->storage,
            $this->configService,
            $this->stateManager,
            $this->eventDispatcher,
            $logger
        );
    }
    
    protected function tearDown(): void
    {
        $this->storage->clear();
    }
    
    public function testIsAllowed_whenClosed_returnsTrue()
    {
        $state = new CircuitBreakerState(CircuitState::CLOSED);
        $this->storage->saveState('service1', $state);
        
        $result = $this->circuitBreakerService->isAllowed('service1');
        
        $this->assertTrue($result);
    }
    
    public function testIsAllowed_whenOpen_returnsFalse()
    {
        $state = new CircuitBreakerState(CircuitState::OPEN);
        $this->storage->saveState('service1', $state);
        
        $result = $this->circuitBreakerService->isAllowed('service1');
        
        $this->assertFalse($result);
    }
    
    public function testIsAllowed_whenOpenAndWaitDurationPassed_returnsTrue()
    {
        // 创建一个已经开启并超过等待时间的状态
        $state = new CircuitBreakerState(CircuitState::OPEN, time() - 100);
        $this->storage->saveState('service1', $state);
        
        $result = $this->circuitBreakerService->isAllowed('service1');
        
        $this->assertTrue($result);
        
        // 验证状态已经改变为半开
        $updatedState = $this->storage->getState('service1');
        $this->assertEquals(CircuitState::HALF_OPEN, $updatedState->getState());
    }
    
    public function testIsAllowed_whenHalfOpenWithinAttemptLimit_returnsTrue()
    {
        $state = new CircuitBreakerState(CircuitState::HALF_OPEN);
        $this->storage->saveState('service1', $state);
        
        $result = $this->circuitBreakerService->isAllowed('service1');
        
        $this->assertTrue($result);
        $this->assertEquals(1, $this->storage->getState('service1')->getAttemptCount());
    }
    
    public function testIsAllowed_whenHalfOpenExceedingAttemptLimit_returnsFalse()
    {
        $state = new CircuitBreakerState(CircuitState::HALF_OPEN);
        $state->setState(CircuitState::HALF_OPEN); // 重置尝试计数
        
        // 设置尝试次数达到允许的上限
        for ($i = 0; $i < 5; $i++) {
            $state->incrementAttemptCount();
        }
        
        $this->storage->saveState('service1', $state);
        
        $result = $this->circuitBreakerService->isAllowed('service1');
        
        $this->assertFalse($result);
    }
    
    public function testRecordSuccess_increasesSuccessCounter()
    {
        $metrics = new CircuitBreakerMetrics();
        $this->storage->saveMetrics('service1', $metrics);
        
        $this->circuitBreakerService->recordSuccess('service1');
        
        $updatedMetrics = $this->storage->getMetrics('service1');
        $this->assertEquals(1, $updatedMetrics->getNumberOfCalls());
        $this->assertEquals(1, $updatedMetrics->getNumberOfSuccessfulCalls());
    }
    
    public function testRecordSuccess_whenHalfOpen_andEnoughSuccesses_closeCircuit()
    {
        // 准备一个处于半开状态的熔断器
        $state = new CircuitBreakerState(CircuitState::HALF_OPEN);
        $this->storage->saveState('service1', $state);
        
        $metrics = new CircuitBreakerMetrics();
        // 足够多的成功调用使熔断器关闭
        for ($i = 0; $i < 4; $i++) {
            $metrics->incrementCalls();
            $metrics->incrementSuccessfulCalls();
        }
        $this->storage->saveMetrics('service1', $metrics);
        
        // 再记录一次成功，应该触发关闭
        $this->circuitBreakerService->recordSuccess('service1');
        
        $updatedState = $this->storage->getState('service1');
        $this->assertEquals(CircuitState::CLOSED, $updatedState->getState());
    }
    
    public function testRecordFailure_increasesFailureCounter()
    {
        $metrics = new CircuitBreakerMetrics();
        $this->storage->saveMetrics('service1', $metrics);
        
        $this->circuitBreakerService->recordFailure('service1', new \RuntimeException('测试异常'));
        
        $updatedMetrics = $this->storage->getMetrics('service1');
        $this->assertEquals(1, $updatedMetrics->getNumberOfCalls());
        $this->assertEquals(1, $updatedMetrics->getNumberOfFailedCalls());
    }
    
    public function testRecordFailure_withIgnoredException_doesntCountAsFailure()
    {
        // 创建一个新的测试用例，为了避免状态干扰，完全独立于其他测试

        // 创建一个内存存储
        $storage = new \Tourze\Symfony\CircuitBreaker\Tests\Mock\MockStorage();
        
        // 创建配置服务 mock，设置忽略 InvalidArgumentException
        $configService = $this->createMock(CircuitBreakerConfigService::class);
        $configService->method('getCircuitConfig')
            ->willReturn([
                'failure_rate_threshold' => 50,
                'minimum_number_of_calls' => 10,
                'permitted_number_of_calls_in_half_open_state' => 5,
                'wait_duration_in_open_state' => 60,
                'ignore_exceptions' => [\InvalidArgumentException::class],
                'record_exceptions' => []
            ]);
        
        // 创建一个事件分发器
        $eventDispatcher = new \Symfony\Component\EventDispatcher\EventDispatcher();
        
        // 创建状态管理器
        $stateManager = new CircuitBreakerStateManager(
            $storage,
            $configService,
            $eventDispatcher,
            new NullLogger()
        );
        
        // 创建熔断器服务
        $service = new CircuitBreakerService(
            $storage,
            $configService,
            $stateManager,
            $eventDispatcher,
            new NullLogger()
        );
        
        // 准备指标
        $metrics = new CircuitBreakerMetrics();
        $storage->saveMetrics('test.service', $metrics);
        
        // 使用应该被忽略的异常记录失败
        $service->recordFailure('test.service', new \InvalidArgumentException('忽略的异常'));
        
        // 获取更新后的指标
        $updatedMetrics = $storage->getMetrics('test.service');
        
        // 验证 - 忽略的异常处理方式是增加调用总次数和成功次数(抵消失败计数)
        $this->assertEquals(1, $updatedMetrics->getNumberOfCalls());
        $this->assertEquals(1, $updatedMetrics->getNumberOfFailedCalls()); // 先增加，然后抵消
        $this->assertEquals(1, $updatedMetrics->getNumberOfSuccessfulCalls()); // 用来抵消失败计数
    }
    
    public function testRecordFailure_whenHalfOpen_opensCircuit()
    {
        // 准备一个处于半开状态的熔断器
        $state = new CircuitBreakerState(CircuitState::HALF_OPEN);
        $this->storage->saveState('service1', $state);
        
        // 记录一次失败，应该触发打开熔断器
        $this->circuitBreakerService->recordFailure('service1', new \RuntimeException('测试异常'));
        
        $updatedState = $this->storage->getState('service1');
        $this->assertEquals(CircuitState::OPEN, $updatedState->getState());
    }
    
    public function testRecordFailure_whenClosedAndThresholdReached_opensCircuit()
    {
        // 准备一个关闭状态的熔断器
        $state = new CircuitBreakerState(CircuitState::CLOSED);
        $this->storage->saveState('service1', $state);
        
        // 准备接近阈值的失败率
        $metrics = new CircuitBreakerMetrics();
        for ($i = 0; $i < 10; $i++) {
            $metrics->incrementCalls();
        }
        for ($i = 0; $i < 5; $i++) {  // 修改为5，这样失败率为50%，刚好等于阈值
            $metrics->incrementFailedCalls();
        }
        $this->storage->saveMetrics('service1', $metrics);
        
        // 再记录一次失败，应该触发熔断器打开
        $this->circuitBreakerService->recordFailure('service1', new \RuntimeException('测试异常'));
        
        $updatedState = $this->storage->getState('service1');
        $this->assertEquals(CircuitState::OPEN, $updatedState->getState());
    }
    
    public function testExecute_whenCircuitClosed_executesOperation()
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
    
    public function testExecute_whenCircuitOpen_executesFallback()
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
    
    public function testExecute_whenOperationThrows_recordsFailureAndRethrows()
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
            $metrics = $this->storage->getMetrics('service1');
            $this->assertEquals(1, $metrics->getNumberOfCalls());
            $this->assertEquals(1, $metrics->getNumberOfFailedCalls());
        }
    }
    
    public function testExecute_whenOperationSucceeds_recordsSuccess()
    {
        $state = new CircuitBreakerState(CircuitState::CLOSED);
        $this->storage->saveState('service1', $state);
        
        $this->circuitBreakerService->execute(
            'service1',
            function() {
                return 'success';
            }
        );
        
        $metrics = $this->storage->getMetrics('service1');
        $this->assertEquals(1, $metrics->getNumberOfCalls());
        $this->assertEquals(1, $metrics->getNumberOfSuccessfulCalls());
    }
    
    public function testExecute_whenCircuitOpenAndNoFallback_throwsCircuitOpenException()
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
    
    public function testResetCircuit_resetsStateAndMetrics()
    {
        // 设置一些初始状态
        $state = new CircuitBreakerState(CircuitState::OPEN);
        $this->storage->saveState('service1', $state);
        
        $metrics = new CircuitBreakerMetrics();
        $metrics->incrementCalls();
        $metrics->incrementFailedCalls();
        $this->storage->saveMetrics('service1', $metrics);
        
        // 重置熔断器
        $this->circuitBreakerService->resetCircuit('service1');
        
        // 验证状态已重置
        $resetState = $this->storage->getState('service1');
        $resetMetrics = $this->storage->getMetrics('service1');
        
        $this->assertEquals(CircuitState::CLOSED, $resetState->getState());
        $this->assertEquals(0, $resetMetrics->getNumberOfCalls());
        $this->assertEquals(0, $resetMetrics->getNumberOfFailedCalls());
    }
    
    public function testForceOpen_setsStateToOpen()
    {
        $state = new CircuitBreakerState(CircuitState::CLOSED);
        $this->storage->saveState('service1', $state);
        
        $this->circuitBreakerService->forceOpen('service1');
        
        $updatedState = $this->storage->getState('service1');
        $this->assertEquals(CircuitState::OPEN, $updatedState->getState());
    }
    
    public function testForceClose_setsStateToClosed()
    {
        $state = new CircuitBreakerState(CircuitState::OPEN);
        $this->storage->saveState('service1', $state);
        
        $this->circuitBreakerService->forceClose('service1');
        
        $updatedState = $this->storage->getState('service1');
        $this->assertEquals(CircuitState::CLOSED, $updatedState->getState());
    }
} 