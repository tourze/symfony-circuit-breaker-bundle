<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Storage;

use PHPUnit\Framework\TestCase;
use Tourze\Symfony\CircuitBreaker\Enum\CircuitState;
use Tourze\Symfony\CircuitBreaker\Model\CircuitBreakerMetrics;
use Tourze\Symfony\CircuitBreaker\Model\CircuitBreakerState;
use Tourze\Symfony\CircuitBreaker\Storage\RedisStorage;

class RedisStorageTest extends TestCase
{
    private $redis;
    private RedisStorage $storage;
    
    protected function setUp(): void
    {
        // 使用Mock，而不是实际创建Redis连接
        $this->redis = $this->createMock(\Redis::class);
        $this->storage = new RedisStorage($this->redis);
    }
    
    public function testGetState_whenKeyExists_returnsParsedState()
    {
        $serializedState = json_encode([
            'state' => 'open',
            'timestamp' => 1234567890,
            'attemptCount' => 3
        ]);
        
        // 期望Redis get方法被调用并返回序列化的状态
        $this->redis->expects($this->once())
            ->method('get')
            ->with('circuit_breaker:state:service1')
            ->willReturn($serializedState);
        
        $state = $this->storage->getState('service1');
        
        $this->assertInstanceOf(CircuitBreakerState::class, $state);
        $this->assertEquals(CircuitState::OPEN, $state->getState());
        $this->assertEquals(1234567890, $state->getTimestamp());
        $this->assertEquals(3, $state->getAttemptCount());
    }
    
    public function testGetState_whenKeyDoesNotExist_returnsDefaultState()
    {
        // 期望Redis get方法被调用并返回false（键不存在）
        $this->redis->expects($this->once())
            ->method('get')
            ->with('circuit_breaker:state:service1')
            ->willReturn(false);
        
        $state = $this->storage->getState('service1');
        
        $this->assertInstanceOf(CircuitBreakerState::class, $state);
        $this->assertEquals(CircuitState::CLOSED, $state->getState());
        $this->assertEquals(0, $state->getAttemptCount());
    }
    
    public function testSaveState_serializesAndStoresState()
    {
        $state = new CircuitBreakerState(CircuitState::HALF_OPEN, 1234567890, 2);
        
        // 预期的序列化状态
        $expectedSerialized = json_encode([
            'state' => 'half_open',
            'timestamp' => 1234567890,
            'attemptCount' => 2
        ]);
        
        // 期望Redis set方法被调用，并且参数匹配
        $this->redis->expects($this->once())
            ->method('set')
            ->with(
                'circuit_breaker:state:service1',
                $this->callback(function($serialized) use ($expectedSerialized) {
                    $this->assertJsonStringEqualsJsonString($expectedSerialized, $serialized);
                    return true;
                })
            )
            ->willReturn(true);
        
        // 同时期望添加到已知熔断器集合
        $this->redis->expects($this->once())
            ->method('sAdd')
            ->with('circuit_breaker:circuits', 'service1')
            ->willReturn(1);
        
        $this->storage->saveState('service1', $state);
    }
    
    public function testGetMetrics_whenKeyExists_returnsParsedMetrics()
    {
        $serializedMetrics = json_encode([
            'numberOfCalls' => 10,
            'numberOfSuccessfulCalls' => 7,
            'numberOfFailedCalls' => 3,
            'notPermittedCalls' => 2
        ]);
        
        // 期望Redis get方法被调用并返回序列化的指标
        $this->redis->expects($this->once())
            ->method('get')
            ->with('circuit_breaker:metrics:service1')
            ->willReturn($serializedMetrics);
        
        $metrics = $this->storage->getMetrics('service1');
        
        $this->assertInstanceOf(CircuitBreakerMetrics::class, $metrics);
        $this->assertEquals(10, $metrics->getNumberOfCalls());
        $this->assertEquals(7, $metrics->getNumberOfSuccessfulCalls());
        $this->assertEquals(3, $metrics->getNumberOfFailedCalls());
        $this->assertEquals(2, $metrics->getNotPermittedCalls());
    }
    
    public function testGetMetrics_whenKeyDoesNotExist_returnsDefaultMetrics()
    {
        // 期望Redis get方法被调用并返回false（键不存在）
        $this->redis->expects($this->once())
            ->method('get')
            ->with('circuit_breaker:metrics:service1')
            ->willReturn(false);
        
        $metrics = $this->storage->getMetrics('service1');
        
        $this->assertInstanceOf(CircuitBreakerMetrics::class, $metrics);
        $this->assertEquals(0, $metrics->getNumberOfCalls());
        $this->assertEquals(0, $metrics->getNumberOfSuccessfulCalls());
        $this->assertEquals(0, $metrics->getNumberOfFailedCalls());
        $this->assertEquals(0, $metrics->getNotPermittedCalls());
    }
    
    public function testSaveMetrics_serializesAndStoresMetrics()
    {
        $metrics = new CircuitBreakerMetrics();
        $metrics->incrementCalls();
        $metrics->incrementCalls();
        $metrics->incrementSuccessfulCalls();
        $metrics->incrementFailedCalls();
        $metrics->incrementNotPermittedCalls();
        
        // 预期的序列化指标
        $expectedSerialized = json_encode([
            'numberOfCalls' => 2,
            'numberOfSuccessfulCalls' => 1,
            'numberOfFailedCalls' => 1,
            'notPermittedCalls' => 1,
            'failureRate' => 50.0
        ]);
        
        // 期望Redis set方法被调用，并且参数匹配
        $this->redis->expects($this->once())
            ->method('set')
            ->with(
                'circuit_breaker:metrics:service1',
                $this->callback(function($serialized) use ($expectedSerialized) {
                    $this->assertJsonStringEqualsJsonString($expectedSerialized, $serialized);
                    return true;
                })
            )
            ->willReturn(true);
        
        // 同时期望添加到已知熔断器集合
        $this->redis->expects($this->once())
            ->method('sAdd')
            ->with('circuit_breaker:circuits', 'service1')
            ->willReturn(1);
        
        $this->storage->saveMetrics('service1', $metrics);
    }
    
    public function testGetAllCircuitNames_returnsSetMembers()
    {
        $expectedNames = ['service1', 'service2', 'service3'];
        
        // 期望Redis sMembers方法被调用并返回预期的名称列表
        $this->redis->expects($this->once())
            ->method('sMembers')
            ->with('circuit_breaker:circuits')
            ->willReturn($expectedNames);
        
        $names = $this->storage->getAllCircuitNames();
        
        $this->assertEquals($expectedNames, $names);
    }
    
    public function testDeleteCircuit_removesKeysAndMember()
    {
        // 期望Redis del方法被调用，并同时删除状态键和指标键
        $this->redis->expects($this->once())
            ->method('del')
            ->with(
                'circuit_breaker:state:service1',
                'circuit_breaker:metrics:service1'
            )
            ->willReturn(2);
        
        // 同时期望从已知熔断器集合中移除
        $this->redis->expects($this->once())
            ->method('sRem')
            ->with('circuit_breaker:circuits', 'service1')
            ->willReturn(1);
        
        $this->storage->deleteCircuit('service1');
        
        // 添加断言，避免测试标记为风险测试
        $this->assertTrue(true);
    }
} 