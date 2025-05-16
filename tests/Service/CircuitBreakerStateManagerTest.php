<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Tourze\Symfony\CircuitBreaker\Enum\CircuitState;
use Tourze\Symfony\CircuitBreaker\Event\CircuitClosedEvent;
use Tourze\Symfony\CircuitBreaker\Event\CircuitHalfOpenEvent;
use Tourze\Symfony\CircuitBreaker\Event\CircuitOpenedEvent;
use Tourze\Symfony\CircuitBreaker\Model\CircuitBreakerMetrics;
use Tourze\Symfony\CircuitBreaker\Model\CircuitBreakerState;
use Tourze\Symfony\CircuitBreaker\Service\CircuitBreakerConfigService;
use Tourze\Symfony\CircuitBreaker\Service\CircuitBreakerStateManager;
use Tourze\Symfony\CircuitBreaker\Tests\Mock\MockStorage;

class CircuitBreakerStateManagerTest extends TestCase
{
    private MockStorage $storage;
    private CircuitBreakerConfigService $configService;
    private EventDispatcher $eventDispatcher;
    private CircuitBreakerStateManager $stateManager;
    
    protected function setUp(): void
    {
        $this->storage = new MockStorage();
        $this->configService = $this->createMock(CircuitBreakerConfigService::class);
        $this->eventDispatcher = new EventDispatcher();
        
        $this->configService->method('getCircuitConfig')
            ->willReturn([
                'failure_rate_threshold' => 50,
                'minimum_number_of_calls' => 10,
                'permitted_number_of_calls_in_half_open_state' => 5,
                'wait_duration_in_open_state' => 60
            ]);
            
        $this->stateManager = new CircuitBreakerStateManager(
            $this->storage,
            $this->configService,
            $this->eventDispatcher,
            new NullLogger()
        );
    }
    
    protected function tearDown(): void
    {
        $this->storage->clear();
    }
    
    public function testGetState_returnsStoredState()
    {
        $state = new CircuitBreakerState(CircuitState::OPEN);
        $this->storage->saveState('service1', $state);
        
        $retrievedState = $this->stateManager->getState('service1');
        
        $this->assertSame(CircuitState::OPEN, $retrievedState->getState());
    }
    
    public function testGetMetrics_returnsStoredMetrics()
    {
        $metrics = new CircuitBreakerMetrics();
        $metrics->incrementCalls();
        $metrics->incrementFailedCalls();
        $this->storage->saveMetrics('service1', $metrics);
        
        $retrievedMetrics = $this->stateManager->getMetrics('service1');
        
        $this->assertEquals(1, $retrievedMetrics->getNumberOfCalls());
        $this->assertEquals(1, $retrievedMetrics->getNumberOfFailedCalls());
    }
    
    public function testSetHalfOpen_updatesStateAndDispatchesEvent()
    {
        $eventDispatched = false;
        $this->eventDispatcher->addListener(CircuitHalfOpenEvent::class, function(CircuitHalfOpenEvent $event) use (&$eventDispatched) {
            $eventDispatched = true;
            $this->assertEquals('service1', $event->getCircuitName());
        });
        
        $state = new CircuitBreakerState(CircuitState::OPEN);
        $this->storage->saveState('service1', $state);
        
        $this->stateManager->setHalfOpen('service1');
        
        $updatedState = $this->storage->getState('service1');
        $this->assertEquals(CircuitState::HALF_OPEN, $updatedState->getState());
        $this->assertTrue($eventDispatched, '半开事件应该被触发');
    }
    
    public function testSetOpen_updatesStateAndDispatchesEvent()
    {
        $eventDispatched = false;
        $failureRate = 75.5;
        
        $this->eventDispatcher->addListener(CircuitOpenedEvent::class, function(CircuitOpenedEvent $event) use (&$eventDispatched, $failureRate) {
            $eventDispatched = true;
            $this->assertEquals('service1', $event->getCircuitName());
            $this->assertEquals($failureRate, $event->getFailureRate());
        });
        
        $state = new CircuitBreakerState(CircuitState::CLOSED);
        $this->storage->saveState('service1', $state);
        
        $this->stateManager->setOpen('service1', $failureRate);
        
        $updatedState = $this->storage->getState('service1');
        $this->assertEquals(CircuitState::OPEN, $updatedState->getState());
        $this->assertTrue($eventDispatched, '开启事件应该被触发');
    }
    
    public function testSetClosed_updatesStateAndDispatchesEvent()
    {
        $eventDispatched = false;
        $this->eventDispatcher->addListener(CircuitClosedEvent::class, function(CircuitClosedEvent $event) use (&$eventDispatched) {
            $eventDispatched = true;
            $this->assertEquals('service1', $event->getCircuitName());
        });
        
        $state = new CircuitBreakerState(CircuitState::OPEN);
        $this->storage->saveState('service1', $state);
        
        $this->stateManager->setClosed('service1');
        
        $updatedState = $this->storage->getState('service1');
        $this->assertEquals(CircuitState::CLOSED, $updatedState->getState());
        $this->assertTrue($eventDispatched, '关闭事件应该被触发');
    }
    
    public function testIncrementAttemptCount_increasesAttemptCounter()
    {
        $state = new CircuitBreakerState(CircuitState::HALF_OPEN);
        $this->storage->saveState('service1', $state);
        
        $this->stateManager->incrementAttemptCount('service1');
        
        $updatedState = $this->storage->getState('service1');
        $this->assertEquals(1, $updatedState->getAttemptCount());
        
        $this->stateManager->incrementAttemptCount('service1');
        $updatedState = $this->storage->getState('service1');
        $this->assertEquals(2, $updatedState->getAttemptCount());
    }
    
    public function testIncrementNotPermittedCalls_increasesNotPermittedCounter()
    {
        $metrics = new CircuitBreakerMetrics();
        $this->storage->saveMetrics('service1', $metrics);
        
        $this->stateManager->incrementNotPermittedCalls('service1');
        
        $updatedMetrics = $this->storage->getMetrics('service1');
        $this->assertEquals(1, $updatedMetrics->getNotPermittedCalls());
        
        $this->stateManager->incrementNotPermittedCalls('service1');
        $updatedMetrics = $this->storage->getMetrics('service1');
        $this->assertEquals(2, $updatedMetrics->getNotPermittedCalls());
    }
    
    public function testResetCircuit_resetsStateAndMetrics()
    {
        // 设置初始状态
        $state = new CircuitBreakerState(CircuitState::OPEN);
        $state->incrementAttemptCount();
        $this->storage->saveState('service1', $state);
        
        $metrics = new CircuitBreakerMetrics();
        $metrics->incrementCalls();
        $metrics->incrementFailedCalls();
        $this->storage->saveMetrics('service1', $metrics);
        
        // 重置并验证
        $this->stateManager->resetCircuit('service1');
        
        $resetState = $this->storage->getState('service1');
        $this->assertEquals(CircuitState::CLOSED, $resetState->getState());
        $this->assertEquals(0, $resetState->getAttemptCount());
        
        $resetMetrics = $this->storage->getMetrics('service1');
        $this->assertEquals(0, $resetMetrics->getNumberOfCalls());
        $this->assertEquals(0, $resetMetrics->getNumberOfFailedCalls());
    }
    
    public function testForceOpen_setsStateToOpen()
    {
        $state = new CircuitBreakerState(CircuitState::CLOSED);
        $this->storage->saveState('service1', $state);
        
        $this->stateManager->forceOpen('service1');
        
        $updatedState = $this->storage->getState('service1');
        $this->assertEquals(CircuitState::OPEN, $updatedState->getState());
    }
    
    public function testForceClose_setsStateToClosed()
    {
        $state = new CircuitBreakerState(CircuitState::OPEN);
        $this->storage->saveState('service1', $state);
        
        $this->stateManager->forceClose('service1');
        
        $updatedState = $this->storage->getState('service1');
        $this->assertEquals(CircuitState::CLOSED, $updatedState->getState());
    }
    
    public function testGetAllCircuitNames_returnsStoredNames()
    {
        // 存储一些状态和指标
        $state1 = new CircuitBreakerState();
        $metrics1 = new CircuitBreakerMetrics();
        $this->storage->saveState('service1', $state1);
        $this->storage->saveMetrics('service1', $metrics1);
        
        $state2 = new CircuitBreakerState();
        $this->storage->saveState('service2', $state2);
        
        $metrics3 = new CircuitBreakerMetrics();
        $this->storage->saveMetrics('service3', $metrics3);
        
        // 获取并验证所有熔断器名称
        $names = $this->stateManager->getAllCircuitNames();
        
        $this->assertIsArray($names);
        $this->assertCount(3, $names);
        $this->assertContains('service1', $names);
        $this->assertContains('service2', $names);
        $this->assertContains('service3', $names);
    }
    
    public function testGetCircuitInfo_returnsCompleteInfo()
    {
        // 设置状态和指标
        $state = new CircuitBreakerState(CircuitState::HALF_OPEN);
        $this->storage->saveState('service1', $state);
        
        $metrics = new CircuitBreakerMetrics();
        $metrics->incrementCalls();
        $metrics->incrementSuccessfulCalls();
        $this->storage->saveMetrics('service1', $metrics);
        
        // 获取熔断器信息
        $info = $this->stateManager->getCircuitInfo('service1');
        
        // 验证信息结构
        $this->assertIsArray($info);
        $this->assertArrayHasKey('name', $info);
        $this->assertArrayHasKey('state', $info);
        $this->assertArrayHasKey('timestamp', $info);
        $this->assertArrayHasKey('metrics', $info);
        $this->assertArrayHasKey('config', $info);
        
        // 验证具体值
        $this->assertEquals('service1', $info['name']);
        $this->assertEquals(CircuitState::HALF_OPEN->value, $info['state']);
        $this->assertIsArray($info['metrics']);
        $this->assertIsArray($info['config']);
        
        // 验证指标信息
        $this->assertEquals(1, $info['metrics']['numberOfCalls']);
        $this->assertEquals(1, $info['metrics']['numberOfSuccessfulCalls']);
    }
} 