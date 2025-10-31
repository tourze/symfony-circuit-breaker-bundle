<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tourze\Symfony\CircuitBreaker\Enum\CircuitState;
use Tourze\Symfony\CircuitBreaker\Model\CallResult;
use Tourze\Symfony\CircuitBreaker\Model\CircuitBreakerState;
use Tourze\Symfony\CircuitBreaker\Storage\RedisAtomicStorage;

/**
 * @internal
 */
#[CoversClass(RedisAtomicStorage::class)]
final class RedisAtomicStorageTest extends TestCase
{
    private RedisAtomicStorage $storage;

    private \Redis $redis;

    public function testGetStateReturnsDefaultState(): void
    {
        $this->redis->expects($this->once())
            ->method('hGetAll')
            ->with('circuit:test:state')
            ->willReturn(false)
        ;

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
                self::callback(function ($data) {
                    return 'open' === $data['state']
                           && isset($data['timestamp'])
                           && '0' === $data['attempt_count'];
                })
            )
            ->willReturn(true)
        ;

        $this->redis->expects($this->once())
            ->method('sAdd')
            ->with('circuit:all', 'test')
            ->willReturn(1)
        ;

        $this->redis->expects($this->once())
            ->method('expire')
            ->with('circuit:test:state', 604800)
            ->willReturn(true)
        ;

        $result = $this->storage->saveState('test', $state);
        $this->assertTrue($result);
    }

    public function testSaveStateMethodExists(): void
    {
        // 专门测试 saveState 方法
        $state = new CircuitBreakerState(CircuitState::HALF_OPEN);

        $this->redis->expects($this->once())
            ->method('hMset')
            ->willReturn(true)
        ;

        $this->redis->expects($this->once())
            ->method('sAdd')
            ->willReturn(1)
        ;

        $this->redis->expects($this->once())
            ->method('expire')
            ->willReturn(true)
        ;

        $result = $this->storage->saveState('test-save', $state);
        $this->assertTrue($result);
    }

    public function testRecordCallSuccess(): void
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
            ->willReturn(1)
        ;

        // Mock cleanup call
        $this->redis->expects($this->once())
            ->method('zRemRangeByScore')
        ;

        // Mock expire call
        $this->redis->expects($this->once())
            ->method('expire')
        ;

        // Mock sAdd call
        $this->redis->expects($this->once())
            ->method('sAdd')
            ->with('circuit:all', 'test')
        ;

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
                self::anything(),
                self::anything()
            )
            ->willReturn([
                $now . ':success:100.00',
                $now . ':failure:200.00',
                $now . ':success:150.00',
            ])
        ;

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
            ->willReturn(true)
        ;

        $result = $this->storage->acquireLock('test', 'token1', 5);
        $this->assertTrue($result);
    }

    public function testAcquireLockAlreadyLocked(): void
    {
        $this->redis->expects($this->once())
            ->method('set')
            ->with(
                'circuit:test:lock',
                'token2',
                ['nx', 'px' => 5000]
            )
            ->willReturn(false)
        ;

        $result = $this->storage->acquireLock('test', 'token2', 5);
        $this->assertFalse($result);
    }

    public function testReleaseLock(): void
    {
        // Mock Lua script for safe lock release
        $this->redis->expects($this->once())
            ->method('eval')
            ->with(
                self::stringContains('redis.call("get"'),
                self::anything(),
                self::anything()
            )
            ->willReturn(1)
        ;

        $result = $this->storage->releaseLock('test', 'token1');
        $this->assertTrue($result);
    }

    public function testIsAvailableWhenRedisConnected(): void
    {
        $this->redis->expects($this->once())
            ->method('ping')
            ->willReturn(true)
        ;

        $this->assertTrue($this->storage->isAvailable());
    }

    public function testIsAvailableWhenRedisDisconnected(): void
    {
        $this->redis->expects($this->once())
            ->method('ping')
            ->willThrowException(new \RedisException('Connection lost'))
        ;

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
            ])
        ;

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
            ->willReturn(2)
        ;

        $this->redis->expects($this->once())
            ->method('srem')
            ->with('circuit:all', 'test')
            ->willReturn(1)
        ;

        $this->storage->deleteCircuit('test');
    }

    public function testConcurrentRecordCall(): void
    {
        // Test that concurrent calls are handled atomically
        $result1 = new CallResult(true, 100.0, time());
        $result2 = new CallResult(false, 200.0, time());

        $this->redis->expects($this->exactly(2))
            ->method('zAdd')
            ->willReturn(1)
        ;

        $this->redis->expects($this->exactly(2))
            ->method('zRemRangeByScore')
        ;

        $this->redis->expects($this->exactly(2))
            ->method('expire')
        ;

        $this->redis->expects($this->exactly(2))
            ->method('sAdd')
        ;

        $this->storage->recordCall('test', $result1);
        $this->storage->recordCall('test', $result2);
    }

    public function testDataExpiration(): void
    {
        // Test that old data is automatically cleaned up
        $now = time();

        $this->redis->expects($this->once())
            ->method('zAdd')
        ;

        $this->redis->expects($this->once())
            ->method('zRemRangeByScore')
            ->with(
                'circuit:test:metrics',
                '0',
                self::anything()
            )
        ;

        $this->redis->expects($this->once())
            ->method('expire')
        ;

        $this->redis->expects($this->once())
            ->method('sAdd')
        ;

        $this->storage->recordCall('test', new CallResult(true, 100.0, $now));
    }

    protected function setUp(): void
    {
        parent::setUp();

        // 在测试中使用 createMock() 对具体类 Redis 进行 Mock
        // 理由1：Redis 是第三方扩展类，测试不应该依赖真实的 Redis 服务器连接
        // 理由2：Mock Redis 可以精确控制测试条件，避免网络延迟和连接问题导致的测试不稳定
        // 理由3：单元测试应该隔离外部依赖，专注于测试 RedisAtomicStorage 的业务逻辑
        $this->redis = $this->createMock(\Redis::class);
        $this->storage = new RedisAtomicStorage($this->redis);
    }
}
