<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tourze\PHPUnitSymfonyKernelTest\AbstractIntegrationTestCase;
use Tourze\Symfony\CircuitBreaker\Enum\CircuitState;
use Tourze\Symfony\CircuitBreaker\Model\CallResult;
use Tourze\Symfony\CircuitBreaker\Model\CircuitBreakerState;
use Tourze\Symfony\CircuitBreaker\Storage\DoctrineStorage;
use Tourze\Symfony\CircuitBreaker\Tests\Factory\TestConnectionFactory;

/**
 * DoctrineStorage 集成测试
 *
 * 由于 DoctrineStorage 需要数据库连接，
 * 如果数据库不可用，测试将被跳过。
 *
 * @internal
 */
#[CoversClass(DoctrineStorage::class)]
#[RunTestsInSeparateProcesses]
final class DoctrineStorageTest extends AbstractIntegrationTestCase
{
    private DoctrineStorage $storage;

    protected function onSetUp(): void
    {
        // 检查数据库连接是否可用
        if (!TestConnectionFactory::isAvailable()) {
            self::markTestSkipped('Database connection not available');
        }

        $this->storage = self::getService(DoctrineStorage::class);
        $this->cleanupTestData();
    }

    protected function onTearDown(): void
    {
        $this->cleanupTestData();
    }

    private function cleanupTestData(): void
    {
        if (!TestConnectionFactory::isAvailable()) {
            return;
        }

        try {
            $connection = TestConnectionFactory::create();
            $connection->executeStatement('DELETE FROM circuit_breaker_state WHERE name LIKE "test%"');
            $connection->executeStatement('DELETE FROM circuit_breaker_metrics WHERE name LIKE "test%"');
            $connection->executeStatement('DELETE FROM circuit_breaker_locks WHERE name LIKE "test%"');
        } catch (\Throwable) {
        }
    }

    public function testCreatesTables(): void
    {
        $state = $this->storage->getState('test');

        $this->assertInstanceOf(CircuitBreakerState::class, $state);
        $this->assertTrue($state->isClosed());
    }

    public function testGetStateReturnsDefaultState(): void
    {
        $state = $this->storage->getState('test');

        $this->assertInstanceOf(CircuitBreakerState::class, $state);
        $this->assertTrue($state->isClosed());
    }

    public function testGetStateReturnsExistingState(): void
    {
        $testState = new CircuitBreakerState(CircuitState::OPEN, 1234567890, 5);
        $this->storage->saveState('test', $testState);

        $state = $this->storage->getState('test');

        $this->assertTrue($state->isOpen());
        $this->assertEquals(1234567890, $state->getTimestamp());
        $this->assertEquals(5, $state->getAttemptCount());
    }

    public function testSaveStateInsertsNewState(): void
    {
        $state = new CircuitBreakerState(CircuitState::OPEN);

        $result = $this->storage->saveState('test', $state);

        $this->assertTrue($result);

        $savedState = $this->storage->getState('test');
        $this->assertTrue($savedState->isOpen());
    }

    public function testRecordCall(): void
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
        $_ENV['CIRCUIT_BREAKER_SLOW_CALL_THRESHOLD'] = '200';

        $timestamp = time();
        for ($i = 0; $i < 5; ++$i) {
            $success = $i < 3;
            $duration = $i < 3 ? 150.0 : 250.0;
            $callResult = new CallResult($success, $duration, $timestamp);
            $this->storage->recordCall('test', $callResult);
        }

        $metrics = $this->storage->getMetricsSnapshot('test', 60);

        $this->assertEquals(5, $metrics->getTotalCalls());
        $this->assertEquals(3, $metrics->getSuccessCalls());
        $this->assertEquals(2, $metrics->getFailedCalls());
        $this->assertEquals(2, $metrics->getSlowCalls());

        unset($_ENV['CIRCUIT_BREAKER_SLOW_CALL_THRESHOLD']);
    }

    public function testGetMetricsSnapshotWithAggregatedData(): void
    {
        $_ENV['CIRCUIT_BREAKER_SLOW_CALL_THRESHOLD'] = '200';

        $timestamp = time();
        for ($i = 0; $i < 100; ++$i) {
            $success = $i < 70;
            $duration = $i < 90 ? 150.0 : 250.0;
            $callResult = new CallResult($success, $duration, $timestamp);
            $this->storage->recordCall('test', $callResult);
        }

        $metrics = $this->storage->getMetricsSnapshot('test', 60);

        $this->assertEquals(100, $metrics->getTotalCalls());
        $this->assertEquals(70, $metrics->getSuccessCalls());
        $this->assertEquals(30, $metrics->getFailedCalls());
        $this->assertEquals(10, $metrics->getSlowCalls());

        unset($_ENV['CIRCUIT_BREAKER_SLOW_CALL_THRESHOLD']);
    }

    public function testAcquireLock(): void
    {
        $result = $this->storage->acquireLock('test', 'token123', 5);

        $this->assertTrue($result);
    }

    public function testAcquireLockAlreadyLocked(): void
    {
        $this->storage->acquireLock('test', 'token123', 5);

        $result = $this->storage->acquireLock('test', 'other-token', 5);

        $this->assertFalse($result);
    }

    public function testReleaseLock(): void
    {
        $this->storage->acquireLock('test', 'token123', 5);

        $result = $this->storage->releaseLock('test', 'token123');

        $this->assertTrue($result);
    }

    public function testIsAvailable(): void
    {
        $this->assertTrue($this->storage->isAvailable());
    }

    public function testGetAllCircuitNames(): void
    {
        $this->storage->saveState('test1', new CircuitBreakerState());
        $this->storage->saveState('test2', new CircuitBreakerState());
        $this->storage->saveState('test3', new CircuitBreakerState());

        $names = $this->storage->getAllCircuitNames();

        $this->assertContains('test1', $names);
        $this->assertContains('test2', $names);
        $this->assertContains('test3', $names);
    }

    public function testDeleteCircuit(): void
    {
        $this->storage->saveState('test', new CircuitBreakerState());
        $this->storage->recordCall('test', new CallResult(true, 100.0, time()));
        $this->storage->acquireLock('test', 'token', 5);

        $this->storage->deleteCircuit('test');

        $state = $this->storage->getState('test');
        $this->assertTrue($state->isClosed());

        $metrics = $this->storage->getMetricsSnapshot('test', 60);
        $this->assertEquals(0, $metrics->getTotalCalls());
    }

    public function testCleanupOldData(): void
    {
        $timestamp = time();
        $this->storage->recordCall('test', new CallResult(true, 100.0, $timestamp));

        $this->storage->recordCall('test', new CallResult(true, 100.0, time()));

        $metrics = $this->storage->getMetricsSnapshot('test', 60);
        $this->assertEquals(2, $metrics->getTotalCalls());
    }

    public function testStateEnumConversion(): void
    {
        $states = [
            ['state' => CircuitState::CLOSED, 'expected' => CircuitState::CLOSED],
            ['state' => CircuitState::OPEN, 'expected' => CircuitState::OPEN],
            ['state' => CircuitState::HALF_OPEN, 'expected' => CircuitState::HALF_OPEN],
        ];

        foreach ($states as $index => $testCase) {
            $circuitState = new CircuitBreakerState($testCase['state']);
            $this->storage->saveState('test' . $index, $circuitState);

            $state = $this->storage->getState('test' . $index);
            $this->assertEquals($testCase['expected'], $state->getState(),
                "Failed for state: {$testCase['state']->value}");
        }
    }
}
