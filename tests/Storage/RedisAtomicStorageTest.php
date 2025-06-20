<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Storage;

use PHPUnit\Framework\TestCase;
use Tourze\Redis\DedicatedConnection\Attribute\WithDedicatedConnection;
use Tourze\Symfony\CircuitBreaker\Enum\CircuitState;
use Tourze\Symfony\CircuitBreaker\Model\CallResult;
use Tourze\Symfony\CircuitBreaker\Model\CircuitBreakerState;
use Tourze\Symfony\CircuitBreaker\Storage\RedisAtomicStorage;

class RedisAtomicStorageTest extends TestCase
{
    private RedisAtomicStorage $storage;
    private \Redis|\RedisCluster $redis;
    
    public function testGetState_returnsDefaultState(): void
    {
        $this->redis->expects($this->once())
            ->method('hGetAll')
            ->with('circuit:test:state')
            ->willReturn(false);

        $state = $this->storage->getState('test');

        $this->assertInstanceOf(CircuitBreakerState::class, $state);
        $this->assertTrue($state->isClosed());
    }
    
    public function testSaveAndGetState(): void
    {
        $state = new CircuitBreakerState(CircuitState::OPEN);

        $this->redis->expects($this->once())
            ->method('hMset')
            ->with(
                'circuit:test:state',
                $this->callback(function ($data) {
                    return $data['state'] === 'open' &&
                           isset($data['timestamp']) &&
                           $data['attempt_count'] === '0';
                })
            )
            ->willReturn(true);

        $this->redis->expects($this->once())
            ->method('sAdd')
            ->with('circuit:all', 'test')
            ->willReturn(1);

        $this->redis->expects($this->once())
            ->method('expire')
            ->with('circuit:test:state', 604800)
            ->willReturn(true);

        $result = $this->storage->saveState('test', $state);
        $this->assertTrue($result);
    }
    
    public function testRecordCall_success(): void
    {
        $timestamp = time();
        $result = new CallResult(
            success: true,
            duration: 100.0,
            timestamp: $timestamp
        );

        // Mock zAdd call
        $this->redis->expects($this->once())
            ->method('zAdd')
            ->with(
                'circuit:test:metrics',
                $timestamp,
                $timestamp . ':success:100.00'
            )
            ->willReturn(1);

        // Mock cleanup call
        $this->redis->expects($this->once())
            ->method('zRemRangeByScore');

        // Mock expire call
        $this->redis->expects($this->once())
            ->method('expire');

        // Mock sAdd call
        $this->redis->expects($this->once())
            ->method('sAdd')
            ->with('circuit:all', 'test');

        $this->storage->recordCall('test', $result);
    }
    
    public function testGetMetricsSnapshot(): void
    {
        $now = time();

        // Mock getting recent calls
        $this->redis->expects($this->once())
            ->method('zRangeByScore')
            ->with(
                'circuit:test:metrics',
                $this->anything(),
                $this->anything()
            )
            ->willReturn([
                $now . ':success:100.00',
                $now . ':failure:200.00',
                $now . ':success:150.00',
            ]);

        $metrics = $this->storage->getMetricsSnapshot('test', 60);

        $this->assertEquals(3, $metrics->getTotalCalls());
        $this->assertEquals(2, $metrics->getSuccessCalls());
        $this->assertEquals(1, $metrics->getFailedCalls());
        $this->assertEquals(150.0, $metrics->getAvgResponseTime());
    }
    
    public function testAcquireLock(): void
    {
        $this->redis->expects($this->once())
            ->method('set')
            ->with(
                'circuit:test:lock',
                'token1',
                ['nx', 'px' => 5000]
            )
            ->willReturn(true);

        $result = $this->storage->acquireLock('test', 'token1', 5);
        $this->assertTrue($result);
    }
    
    public function testAcquireLock_alreadyLocked(): void
    {
        $this->redis->expects($this->once())
            ->method('set')
            ->with(
                'circuit:test:lock',
                'token2',
                ['nx', 'px' => 5000]
            )
            ->willReturn(false);

        $result = $this->storage->acquireLock('test', 'token2', 5);
        $this->assertFalse($result);
    }
    
    public function testReleaseLock(): void
    {
        // Mock Lua script for safe lock release
        $this->redis->expects($this->once())
            ->method('eval')
            ->with(
                $this->stringContains('redis.call("get"'),
                $this->anything(),
                $this->anything()
            )
            ->willReturn(1);

        $result = $this->storage->releaseLock('test', 'token1');
        $this->assertTrue($result);
    }
    
    public function testIsAvailable_whenRedisConnected(): void
    {
        $this->redis->expects($this->once())
            ->method('ping')
            ->willReturn(true);

        $this->assertTrue($this->storage->isAvailable());
    }
    
    public function testIsAvailable_whenRedisDisconnected(): void
    {
        $this->redis->expects($this->once())
            ->method('ping')
            ->willThrowException(new \RedisException('Connection lost'));

        $this->assertFalse($this->storage->isAvailable());
    }
    
    public function testGetAllCircuitNames(): void
    {
        $this->redis->expects($this->once())
            ->method('sMembers')
            ->with('circuit:all')
            ->willReturn([
                'service1',
                'service2',
                'service3',
            ]);

        $names = $this->storage->getAllCircuitNames();

        $this->assertCount(3, $names);
        $this->assertContains('service1', $names);
        $this->assertContains('service2', $names);
        $this->assertContains('service3', $names);
    }
    
    public function testDeleteCircuit(): void
    {
        $this->redis->expects($this->once())
            ->method('del')
            ->with('circuit:test:state', 'circuit:test:metrics')
            ->willReturn(2);

        $this->redis->expects($this->once())
            ->method('srem')
            ->with('circuit:all', 'test')
            ->willReturn(1);

        $this->storage->deleteCircuit('test');
    }
    
    public function testConcurrentRecordCall(): void
    {
        // Test that concurrent calls are handled atomically
        $result1 = new CallResult(true, 100.0, time());
        $result2 = new CallResult(false, 200.0, time());

        $this->redis->expects($this->exactly(2))
            ->method('zAdd')
            ->willReturn(1);

        $this->redis->expects($this->exactly(2))
            ->method('zRemRangeByScore');

        $this->redis->expects($this->exactly(2))
            ->method('expire');

        $this->redis->expects($this->exactly(2))
            ->method('sAdd');

        $this->storage->recordCall('test', $result1);
        $this->storage->recordCall('test', $result2);
    }
    
    public function testDataExpiration(): void
    {
        // Test that old data is automatically cleaned up
        $now = time();

        $this->redis->expects($this->once())
            ->method('zAdd');

        $this->redis->expects($this->once())
            ->method('zRemRangeByScore')
            ->with(
                'circuit:test:metrics',
                '0',
                $this->anything()
            );

        $this->redis->expects($this->once())
            ->method('expire');

        $this->redis->expects($this->once())
            ->method('sAdd');

        $this->storage->recordCall('test', new CallResult(true, 100.0, $now));
    }
    
    protected function setUp(): void
    {
        // Mock Redis connection
        $this->redis = $this->createMock(\Redis::class);
        $this->storage = new RedisAtomicStorage($this->redis);
    }
}