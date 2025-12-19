<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tourze\PHPUnitSymfonyKernelTest\AbstractIntegrationTestCase;
use Tourze\Symfony\CircuitBreaker\Enum\CircuitState;
use Tourze\Symfony\CircuitBreaker\Model\CallResult;
use Tourze\Symfony\CircuitBreaker\Model\CircuitBreakerState;
use Tourze\Symfony\CircuitBreaker\Storage\RedisAtomicStorage;

/**
 * RedisAtomicStorage 集成测试
 *
 * 由于 RedisAtomicStorage 需要 Redis 服务器，
 * 如果 Redis 不可用，测试将被跳过。
 *
 * @internal
 */
#[CoversClass(RedisAtomicStorage::class)]
#[RunTestsInSeparateProcesses]
final class RedisAtomicStorageTest extends AbstractIntegrationTestCase
{
    private RedisAtomicStorage $storage;

    private \Redis $redis;

    private string $testCircuitName = 'test-circuit';

    protected function onSetUp(): void
    {
        // 检查 Redis 连接是否可用
        $this->redis = new \Redis();

        try {
            $connected = $this->redis->connect(
                $_ENV['REDIS_HOST'] ?? '127.0.0.1',
                (int) ($_ENV['REDIS_PORT'] ?? 6379),
                1.0 // 1秒超时
            );

            if (!$connected) {
                self::markTestSkipped('无法连接到 Redis 服务器');
            }

            if (isset($_ENV['REDIS_PASSWORD']) && '' !== $_ENV['REDIS_PASSWORD']) {
                $this->redis->auth($_ENV['REDIS_PASSWORD']);
            }

            $this->redis->ping();
        } catch (\Throwable $e) {
            self::markTestSkipped('Redis 连接不可用: ' . $e->getMessage());
        }

        $this->storage = self::getService(RedisAtomicStorage::class);
        $this->cleanupTestData();
    }

    protected function onTearDown(): void
    {
        $this->cleanupTestData();

        if ($this->redis instanceof \Redis) {
            try {
                $this->redis->close();
            } catch (\Throwable) {
            }
        }
    }

    private function cleanupTestData(): void
    {
        if (!$this->redis instanceof \Redis) {
            return;
        }

        try {
            $pattern = 'circuit:' . $this->testCircuitName . '*';
            $keys = $this->redis->keys($pattern);
            if (is_array($keys) && count($keys) > 0) {
                $this->redis->del(...$keys);
            }

            $this->redis->srem('circuit:all', $this->testCircuitName);
            $this->redis->del('circuit:test:state', 'circuit:test:metrics', 'circuit:test:lock');
            $this->redis->del('circuit:test-save:state', 'circuit:test-save:metrics', 'circuit:test-save:lock');
            $this->redis->srem('circuit:all', 'test', 'test-save', 'service1', 'service2', 'service3');
        } catch (\Throwable) {
        }
    }

    public function testGetStateReturnsDefaultState(): void
    {
        $state = $this->storage->getState($this->testCircuitName);

        $this->assertInstanceOf(CircuitBreakerState::class, $state);
        $this->assertTrue($state->isClosed());
    }

    public function testSaveAndGetState(): void
    {
        $state = new CircuitBreakerState(CircuitState::OPEN);

        $result = $this->storage->saveState('test', $state);
        $this->assertTrue($result);

        $retrievedState = $this->storage->getState('test');
        $this->assertEquals(CircuitState::OPEN, $retrievedState->getState());
        $this->assertEquals($state->getTimestamp(), $retrievedState->getTimestamp());
        $this->assertEquals($state->getAttemptCount(), $retrievedState->getAttemptCount());
    }

    public function testSaveStateMethodExists(): void
    {
        $state = new CircuitBreakerState(CircuitState::HALF_OPEN);

        $result = $this->storage->saveState('test-save', $state);
        $this->assertTrue($result);

        $retrievedState = $this->storage->getState('test-save');
        $this->assertEquals(CircuitState::HALF_OPEN, $retrievedState->getState());
    }

    public function testRecordCallSuccess(): void
    {
        $timestamp = time();
        $result = new CallResult(
            success: true,
            duration: 100.0,
            timestamp: $timestamp
        );

        $this->storage->recordCall('test', $result);

        $metrics = $this->storage->getMetricsSnapshot('test', 60);
        $this->assertEquals(1, $metrics->getTotalCalls());
        $this->assertEquals(1, $metrics->getSuccessCalls());
        $this->assertEquals(0, $metrics->getFailedCalls());
    }

    public function testGetMetricsSnapshot(): void
    {
        $now = time();

        $this->storage->recordCall('test', new CallResult(true, 100.0, $now));
        $this->storage->recordCall('test', new CallResult(false, 200.0, $now));
        $this->storage->recordCall('test', new CallResult(true, 150.0, $now));

        $metrics = $this->storage->getMetricsSnapshot('test', 60);

        $this->assertEquals(3, $metrics->getTotalCalls());
        $this->assertEquals(2, $metrics->getSuccessCalls());
        $this->assertEquals(1, $metrics->getFailedCalls());
        $this->assertEquals(150.0, $metrics->getAvgResponseTime());
    }

    public function testAcquireLock(): void
    {
        $result = $this->storage->acquireLock('test', 'token1', 5);
        $this->assertTrue($result);
    }

    public function testAcquireLockAlreadyLocked(): void
    {
        $this->storage->acquireLock('test', 'token1', 5);

        $result = $this->storage->acquireLock('test', 'token2', 5);
        $this->assertFalse($result);
    }

    public function testReleaseLock(): void
    {
        $this->storage->acquireLock('test', 'token1', 5);

        $result = $this->storage->releaseLock('test', 'token1');
        $this->assertTrue($result);
    }

    public function testReleaseLockWithWrongToken(): void
    {
        $this->storage->acquireLock('test', 'token1', 5);

        $result = $this->storage->releaseLock('test', 'wrong-token');
        $this->assertFalse($result);
    }

    public function testIsAvailableWhenRedisConnected(): void
    {
        $this->assertTrue($this->storage->isAvailable());
    }

    public function testGetAllCircuitNames(): void
    {
        // 使用唯一前缀避免与其他测试冲突
        $prefix = 'get-all-test-' . time() . '-';
        $this->storage->saveState($prefix . 'service1', new CircuitBreakerState(CircuitState::CLOSED));
        $this->storage->saveState($prefix . 'service2', new CircuitBreakerState(CircuitState::OPEN));
        $this->storage->saveState($prefix . 'service3', new CircuitBreakerState(CircuitState::HALF_OPEN));

        $names = $this->storage->getAllCircuitNames();

        // 检查我们添加的三个熔断器是否都在列表中
        $this->assertContains($prefix . 'service1', $names);
        $this->assertContains($prefix . 'service2', $names);
        $this->assertContains($prefix . 'service3', $names);

        // 清理
        $this->storage->deleteCircuit($prefix . 'service1');
        $this->storage->deleteCircuit($prefix . 'service2');
        $this->storage->deleteCircuit($prefix . 'service3');
    }

    public function testDeleteCircuit(): void
    {
        $this->storage->saveState('test', new CircuitBreakerState(CircuitState::OPEN));
        $this->storage->recordCall('test', new CallResult(true, 100.0, time()));

        $this->storage->deleteCircuit('test');

        $state = $this->storage->getState('test');
        $this->assertTrue($state->isClosed());

        $metrics = $this->storage->getMetricsSnapshot('test', 60);
        $this->assertEquals(0, $metrics->getTotalCalls());
    }

    public function testConcurrentRecordCall(): void
    {
        $result1 = new CallResult(true, 100.0, time());
        $result2 = new CallResult(false, 200.0, time());

        $this->storage->recordCall('test', $result1);
        $this->storage->recordCall('test', $result2);

        $metrics = $this->storage->getMetricsSnapshot('test', 60);
        $this->assertEquals(2, $metrics->getTotalCalls());
        $this->assertEquals(1, $metrics->getSuccessCalls());
        $this->assertEquals(1, $metrics->getFailedCalls());
    }

    public function testDataExpiration(): void
    {
        $now = time();

        $this->storage->recordCall('test', new CallResult(true, 100.0, $now));

        $metrics = $this->storage->getMetricsSnapshot('test', 60);
        $this->assertEquals(1, $metrics->getTotalCalls());

        $oldMetrics = $this->storage->getMetricsSnapshot('test', 1);
        $this->assertLessThanOrEqual(1, $oldMetrics->getTotalCalls());
    }
}
