<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tourze\Symfony\CircuitBreaker\Enum\CircuitState;
use Tourze\Symfony\CircuitBreaker\Model\CallResult;
use Tourze\Symfony\CircuitBreaker\Model\CircuitBreakerState;
use Tourze\Symfony\CircuitBreaker\Storage\DoctrineStorage;

/**
 * @internal
 */
#[CoversClass(DoctrineStorage::class)]
final class DoctrineStorageTest extends TestCase
{
    private DoctrineStorage $storage;

    private Connection $connection;

    /**
     * @var AbstractSchemaManager<AbstractPlatform>
     */
    private AbstractSchemaManager $schemaManager;

    public function testCreatesTables(): void
    {
        // First call will check if tables exist
        $this->schemaManager->expects($this->once())
            ->method('tablesExist')
            ->with(['circuit_breaker_state', 'circuit_breaker_metrics', 'circuit_breaker_locks'])
            ->willReturn(false)
        ;

        $matcher = $this->exactly(3);
        $this->connection->expects($matcher)
            ->method('executeStatement')
            ->willReturnCallback(function ($sql) use ($matcher) {
                switch ($matcher->numberOfInvocations()) {
                    case 1:
                        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS circuit_breaker_state', $sql);
                        break;
                    case 2:
                        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS circuit_breaker_metrics', $sql);
                        break;
                    case 3:
                        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS circuit_breaker_locks', $sql);
                        break;
                }

                return 1;
            })
        ;

        // Mock the getState query after table creation
        // 在测试中使用 createMock() 对具体类 Result 进行 Mock
        // 理由1：Result 是 Doctrine DBAL 的具体类，代表数据库查询结果
        // 理由2：Mock Result 可以精确控制查询结果数据，避免依赖真实数据库数据
        // 理由3：单元测试应该聚焦于业务逻辑，而不是数据库查询结果的处理细节
        $result = $this->createMock(Result::class);
        $result->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn(false)
        ;

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->willReturn($result)
        ;

        // Trigger table creation
        $this->storage->getState('test');
    }

    public function testGetStateReturnsDefaultState(): void
    {
        $this->setupTableExists();

        // 在测试中使用 createMock() 对具体类 Result 进行 Mock
        // 理由1：Result 是 Doctrine DBAL 的具体类，代表数据库查询结果
        // 理由2：Mock Result 可以精确控制查询结果数据，避免依赖真实数据库数据
        // 理由3：单元测试应该聚焦于业务逻辑，而不是数据库查询结果的处理细节
        $result = $this->createMock(Result::class);
        $result->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn(false)
        ;

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with(
                self::stringContains('SELECT state, timestamp, attempt_count FROM circuit_breaker_state'),
                ['name' => 'test']
            )
            ->willReturn($result)
        ;

        $state = $this->storage->getState('test');

        $this->assertInstanceOf(CircuitBreakerState::class, $state);
        $this->assertTrue($state->isClosed());
    }

    private function setupTableExists(): void
    {
        $this->schemaManager->expects($this->any())
            ->method('tablesExist')
            ->willReturn(true)
        ;
    }

    public function testGetStateReturnsExistingState(): void
    {
        $this->setupTableExists();

        // 在测试中使用 createMock() 对具体类 Result 进行 Mock
        // 理由1：Result 是 Doctrine DBAL 的具体类，代表数据库查询结果
        // 理由2：Mock Result 可以精确控制查询结果数据，避免依赖真实数据库数据
        // 理由3：单元测试应该聚焦于业务逻辑，而不是数据库查询结果的处理细节
        $result = $this->createMock(Result::class);
        $result->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn([
                'state' => 'open',
                'timestamp' => 1234567890,
                'attempt_count' => 5,
            ])
        ;

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->willReturn($result)
        ;

        $state = $this->storage->getState('test');

        $this->assertTrue($state->isOpen());
        $this->assertEquals(1234567890, $state->getTimestamp());
        $this->assertEquals(5, $state->getAttemptCount());
    }

    public function testSaveStateInsertsNewState(): void
    {
        $this->setupTableExists();

        $state = new CircuitBreakerState(CircuitState::OPEN);

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                self::stringContains('INSERT INTO circuit_breaker_state'),
                self::callback(function ($params) {
                    return 'test' === $params['name']
                           && 'open' === $params['state']
                           && is_int($params['timestamp'])
                           && 0 === $params['attempt_count'];
                })
            )
            ->willReturn(1)
        ;

        $result = $this->storage->saveState('test', $state);

        $this->assertTrue($result);
    }

    public function testRecordCall(): void
    {
        $this->setupTableExists();

        $timestamp = time();
        $result = new CallResult(
            success: true,
            duration: 100.0,
            timestamp: $timestamp
        );

        $expectedParams = [
            'name' => 'test',
            'timestamp' => $timestamp,
            'success' => 1,
            'duration' => 100.0,
        ];

        $callCount = 0;
        $this->connection->expects($this->exactly(3))
            ->method('executeStatement')
            ->willReturnCallback(function ($sql, $params) use (&$callCount, $expectedParams) {
                ++$callCount;
                if (1 === $callCount) {
                    $this->assertStringContainsString('INSERT INTO circuit_breaker_metrics', $sql);
                    $this->assertEquals($expectedParams, $params);
                } elseif (2 === $callCount) {
                    $this->assertStringContainsString('DELETE FROM circuit_breaker_metrics', $sql);
                } else {
                    $this->assertStringContainsString('DELETE FROM circuit_breaker_locks', $sql);
                }

                return 1;
            })
        ;

        $this->storage->recordCall('test', $result);
    }

    public function testGetMetricsSnapshot(): void
    {
        $this->setupTableExists();

        // 在测试中使用 createMock() 对具体类 Result 进行 Mock
        // 理由1：Result 是 Doctrine DBAL 的具体类，测试不应该依赖真实的数据库结果
        // 理由2：测试重点是 DoctrineStorage 的指标统计逻辑，而不是 Result 的具体实现
        // 理由3：Mock Result 可以精确控制返回的数据，便于测试不同的统计场景
        $result = $this->createMock(Result::class);
        $result->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn([
                'total_calls' => '5',
                'success_calls' => '3',
                'failed_calls' => '2',
                'slow_calls' => '3',
                'avg_duration' => '200.0',
            ])
        ;

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with(
                self::stringContains('SELECT'),
                self::anything()
            )
            ->willReturn($result)
        ;

        // Set environment variable for slow call threshold
        $_ENV['CIRCUIT_BREAKER_SLOW_CALL_THRESHOLD'] = '200';

        $metrics = $this->storage->getMetricsSnapshot('test', 60);

        $this->assertEquals(5, $metrics->getTotalCalls());
        $this->assertEquals(3, $metrics->getSuccessCalls());
        $this->assertEquals(2, $metrics->getFailedCalls());
        $this->assertEquals(3, $metrics->getSlowCalls());
        $this->assertEquals(200.0, $metrics->getAvgResponseTime());

        // Clean up
        unset($_ENV['CIRCUIT_BREAKER_SLOW_CALL_THRESHOLD']);
    }

    public function testGetMetricsSnapshotWithAggregatedData(): void
    {
        $this->setupTableExists();

        // 在测试中使用 createMock() 对具体类 Result 进行 Mock
        // 理由1：Result 是 Doctrine DBAL 的具体类，测试不应该依赖真实的数据库结果
        // 理由2：测试重点是 DoctrineStorage 的指标统计逻辑，而不是 Result 的具体实现
        // 理由3：Mock Result 可以精确控制返回的数据，便于测试不同的统计场景
        $result = $this->createMock(Result::class);
        $result->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn([
                'total_calls' => '100',
                'success_calls' => '70',
                'failed_calls' => '20',
                'slow_calls' => '10',
                'avg_duration' => '150.5',
            ])
        ;

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with(
                self::stringContains('COUNT(*) as total_calls'),
                self::anything()
            )
            ->willReturn($result)
        ;

        // Set environment variable for slow call threshold
        $_ENV['CIRCUIT_BREAKER_SLOW_CALL_THRESHOLD'] = '200';

        $metrics = $this->storage->getMetricsSnapshot('test', 60);

        $this->assertEquals(100, $metrics->getTotalCalls());
        $this->assertEquals(70, $metrics->getSuccessCalls());
        $this->assertEquals(20, $metrics->getFailedCalls());
        $this->assertEquals(10, $metrics->getSlowCalls());
        $this->assertEquals(150.5, $metrics->getAvgResponseTime());

        // Clean up
        unset($_ENV['CIRCUIT_BREAKER_SLOW_CALL_THRESHOLD']);
    }

    public function testAcquireLock(): void
    {
        $this->setupTableExists();

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                self::stringContains('INSERT INTO circuit_breaker_locks'),
                self::callback(function ($params) {
                    return 'test' === $params['name']
                           && 'token123' === $params['token']
                           && isset($params['expire_at'], $params['now']);
                })
            )
            ->willReturn(1)
        ;

        // 在测试中使用 createMock() 对具体类 Result 进行 Mock
        // 理由1：Result 是 Doctrine DBAL 的具体类，测试不应该依赖真实的数据库结果
        // 理由2：测试重点是 DoctrineStorage 的锁获取逻辑，而不是 Result 的具体实现
        // 理由3：Mock Result 可以精确控制返回的锁令牌，便于测试不同的锁获取场景
        $lockResult = $this->createMock(Result::class);
        $lockResult->expects($this->once())
            ->method('fetchOne')
            ->willReturn('token123')
        ;

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with(
                self::stringContains('SELECT token FROM circuit_breaker_locks'),
                ['name' => 'test']
            )
            ->willReturn($lockResult)
        ;

        $result = $this->storage->acquireLock('test', 'token123', 5);

        $this->assertTrue($result);
    }

    public function testAcquireLockAlreadyLocked(): void
    {
        $this->setupTableExists();

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->willReturn(1) // Successful insert/update
        ;

        // 在测试中使用 createMock() 对具体类 Result 进行 Mock
        // 理由1：Result 是 Doctrine DBAL 的具体类，测试不应该依赖真实的数据库结果
        // 理由2：测试重点是 DoctrineStorage 的锁获取逻辑，而不是 Result 的具体实现
        // 理由3：Mock Result 可以精确控制返回的锁令牌，便于测试不同的锁获取场景
        $lockResult = $this->createMock(Result::class);
        $lockResult->expects($this->once())
            ->method('fetchOne')
            ->willReturn('other-token') // Different token, so lock failed
        ;

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with(
                self::stringContains('SELECT token FROM circuit_breaker_locks'),
                ['name' => 'test']
            )
            ->willReturn($lockResult)
        ;

        $result = $this->storage->acquireLock('test', 'token123', 5);

        $this->assertFalse($result);
    }

    public function testReleaseLock(): void
    {
        $this->setupTableExists();

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                self::stringContains('DELETE FROM circuit_breaker_locks'),
                [
                    'name' => 'test',
                    'token' => 'token123',
                ]
            )
            ->willReturn(1)
        ;

        $result = $this->storage->releaseLock('test', 'token123');

        $this->assertTrue($result);
    }

    public function testIsAvailable(): void
    {
        // 在测试中使用 createMock() 对具体类 Result 进行 Mock
        // 理由1：Result 是 Doctrine DBAL 的具体类，测试不应该依赖真实的数据库结果
        // 理由2：测试重点是 DoctrineStorage 的可用性检查逻辑，而不是 Result 的具体实现
        // 理由3：Mock Result 可以精确控制返回的可用性状态，便于测试不同的可用性场景
        $result = $this->createMock(Result::class);
        $result->expects($this->once())
            ->method('fetchOne')
            ->willReturn(1)
        ;

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT 1')
            ->willReturn($result)
        ;

        $this->assertTrue($this->storage->isAvailable());
    }

    public function testIsAvailableConnectionFails(): void
    {
        // 在测试中使用 createMock() 对具体类 Exception 进行 Mock
        // 理由1：Exception 是 Doctrine DBAL 的具体异常类，测试需要模拟数据库异常场景
        // 理由2：Mock Exception 可以精确控制异常行为，避免依赖真实数据库错误
        // 理由3：单元测试应该测试异常处理逻辑，而不是真实的数据库异常
        $exception = $this->createMock(Exception::class);
        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT 1')
            ->willThrowException($exception)
        ;

        $this->assertFalse($this->storage->isAvailable());
    }

    public function testGetAllCircuitNames(): void
    {
        $this->setupTableExists();

        // 在测试中使用 createMock() 对具体类 Result 进行 Mock
        // 理由1：Result 是 Doctrine DBAL 的具体类，测试不应该依赖真实的数据库结果
        // 理由2：测试重点是 DoctrineStorage 的熔断器列表获取逻辑，而不是 Result 的具体实现
        // 理由3：Mock Result 可以精确控制返回的熔断器名称列表，便于测试不同的查询场景
        $result = $this->createMock(Result::class);
        $result->expects($this->once())
            ->method('fetchFirstColumn')
            ->willReturn(['circuit1', 'circuit2', 'circuit3'])
        ;

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with(self::stringContains('SELECT DISTINCT name FROM circuit_breaker_state'))
            ->willReturn($result)
        ;

        $names = $this->storage->getAllCircuitNames();

        $this->assertEquals(['circuit1', 'circuit2', 'circuit3'], $names);
    }

    public function testDeleteCircuit(): void
    {
        $this->setupTableExists();

        $matcher = $this->exactly(3);
        $this->connection->expects($matcher)
            ->method('executeStatement')
            ->willReturnCallback(function ($sql, $params) use ($matcher) {
                switch ($matcher->numberOfInvocations()) {
                    case 1:
                        $this->assertStringContainsString('DELETE FROM circuit_breaker_state', $sql);
                        $this->assertEquals(['name' => 'test'], $params);
                        break;
                    case 2:
                        $this->assertStringContainsString('DELETE FROM circuit_breaker_metrics', $sql);
                        $this->assertEquals(['name' => 'test'], $params);
                        break;
                    case 3:
                        $this->assertStringContainsString('DELETE FROM circuit_breaker_locks', $sql);
                        $this->assertEquals(['name' => 'test'], $params);
                        break;
                }

                return 1;
            })
        ;

        $this->storage->deleteCircuit('test');
    }

    public function testCleanupOldData(): void
    {
        $this->setupTableExists();

        // Should be called automatically on recordCall
        $matcher = $this->exactly(3);
        $this->connection->expects($matcher)
            ->method('executeStatement')
            ->willReturnCallback(function ($sql) use ($matcher) {
                switch ($matcher->numberOfInvocations()) {
                    case 1:
                        $this->assertStringContainsString('INSERT INTO circuit_breaker_metrics', $sql);
                        break;
                    case 2:
                        $this->assertStringContainsString('DELETE FROM circuit_breaker_metrics', $sql);
                        break;
                    case 3:
                        $this->assertStringContainsString('DELETE FROM circuit_breaker_locks', $sql);
                        break;
                }

                return 1;
            })
        ;

        $this->storage->recordCall('test', new CallResult(true, 100.0, time()));
    }

    public function testStateEnumConversion(): void
    {
        $this->setupTableExists();

        // Test all state enum values
        $states = [
            ['state' => 'closed', 'expected' => CircuitState::CLOSED],
            ['state' => 'open', 'expected' => CircuitState::OPEN],
            ['state' => 'half_open', 'expected' => CircuitState::HALF_OPEN],
        ];

        $callCount = 0;
        $results = [];

        foreach ($states as $testCase) {
            // 在测试中使用 createMock() 对具体类 Result 进行 Mock
            // 理由1：Result 是 Doctrine DBAL 的具体类，代表数据库查询结果
            // 理由2：Mock Result 可以精确控制查询结果数据，避免依赖真实数据库数据
            // 理由3：单元测试应该聚焦于业务逻辑，而不是数据库查询结果的处理细节
            $result = $this->createMock(Result::class);
            $result->method('fetchAssociative')
                ->willReturn([
                    'state' => $testCase['state'],
                    'timestamp' => time(),
                    'attempt_count' => 0,
                ])
            ;
            $results[] = $result;
        }

        $this->connection->expects($this->exactly(3))
            ->method('executeQuery')
            ->willReturnOnConsecutiveCalls(...$results)
        ;

        foreach ($states as $testCase) {
            $state = $this->storage->getState('test' . $callCount);
            $this->assertEquals($testCase['expected'], $state->getState(),
                "Failed for state: {$testCase['state']}");
            ++$callCount;
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        // 在测试中使用 createMock() 对具体类 Connection 进行 Mock
        // 理由1：Connection 是 Doctrine DBAL 的具体类，测试不应该依赖真实的数据库连接
        // 理由2：Mock Connection 可以精确控制数据库操作的模拟，避免测试对真实数据库的依赖
        // 理由3：单元测试应该隔离外部依赖，专注于测试 DoctrineStorage 的业务逻辑
        $this->connection = $this->createMock(Connection::class);
        // 在测试中使用 createMock() 对具体类 AbstractSchemaManager 进行 Mock
        // 理由1：AbstractSchemaManager 是 Doctrine DBAL 的抽象类，没有对应的接口
        // 理由2：Mock AbstractSchemaManager 可以控制数据库模式管理操作，如表存在检查
        // 理由3：测试重点是存储逻辑，而不是数据库模式管理的实现细节
        $this->schemaManager = $this->createMock(AbstractSchemaManager::class);

        $this->connection->expects($this->any())
            ->method('createSchemaManager')
            ->willReturn($this->schemaManager)
        ;

        $this->storage = new DoctrineStorage($this->connection, new NullLogger());
    }
}
