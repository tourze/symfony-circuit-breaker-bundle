<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tourze\Symfony\CircuitBreaker\Enum\CircuitState;
use Tourze\Symfony\CircuitBreaker\Model\CallResult;
use Tourze\Symfony\CircuitBreaker\Model\CircuitBreakerState;
use Tourze\Symfony\CircuitBreaker\Storage\DoctrineStorage;

class DoctrineStorageTest extends TestCase
{
    private DoctrineStorage $storage;
    private Connection $connection;
    private AbstractSchemaManager $schemaManager;
    
    public function testCreatesTables(): void
    {
        // First call will check if tables exist
        $this->schemaManager->expects($this->once())
            ->method('tablesExist')
            ->with(['circuit_breaker_state', 'circuit_breaker_metrics', 'circuit_breaker_locks'])
            ->willReturn(false);

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
            });

        // Trigger table creation
        $this->storage->getState('test');
    }
    
    public function testGetState_returnsDefaultState(): void
    {
        $this->setupTableExists();

        $result = $this->createMock(Result::class);
        $result->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn(false);

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with(
                $this->stringContains('SELECT * FROM circuit_breaker_state'),
                ['test']
            )
            ->willReturn($result);

        $state = $this->storage->getState('test');

        $this->assertInstanceOf(CircuitBreakerState::class, $state);
        $this->assertTrue($state->isClosed());
    }
    
    private function setupTableExists(): void
    {
        $this->schemaManager->expects($this->any())
            ->method('tablesExist')
            ->willReturn(true);
    }
    
    public function testGetState_returnsExistingState(): void
    {
        $this->setupTableExists();

        $result = $this->createMock(Result::class);
        $result->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn([
                'state' => 'open',
                'timestamp' => 1234567890,
                'attempt_count' => 5
            ]);

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->willReturn($result);

        $state = $this->storage->getState('test');

        $this->assertTrue($state->isOpen());
        $this->assertEquals(1234567890, $state->getTimestamp());
        $this->assertEquals(5, $state->getAttemptCount());
    }
    
    public function testSaveState_insertsNewState(): void
    {
        $this->setupTableExists();

        $state = new CircuitBreakerState(CircuitState::OPEN);

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('INSERT INTO circuit_breaker_state'),
                $this->callback(function ($params) {
                    return $params['name'] === 'test' &&
                           $params['state'] === 'open' &&
                           is_int($params['timestamp']) &&
                           $params['attempt_count'] === 0;
                })
            )
            ->willReturn(1);

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
            'exception' => null
        ];

        $callCount = 0;
        $this->connection->expects($this->exactly(2))
            ->method('executeStatement')
            ->willReturnCallback(function ($sql, $params) use (&$callCount, $expectedParams) {
                $callCount++;
                if ($callCount === 1) {
                    $this->assertStringContainsString('INSERT INTO circuit_breaker_calls', $sql);
                    $this->assertEquals($expectedParams, $params);
                } else {
                    $this->assertStringContainsString('DELETE FROM circuit_breaker_calls', $sql);
                }
                return 1;
            });

        $this->storage->recordCall('test', $result);
    }
    
    public function testGetMetricsSnapshot(): void
    {
        $this->setupTableExists();

        $result = $this->createMock(Result::class);
        $result->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn([
                ['success' => 1, 'duration' => 100.0],
                ['success' => 1, 'duration' => 200.0],
                ['success' => 0, 'duration' => 300.0],
                ['success' => 0, 'duration' => 250.0],
                ['success' => 1, 'duration' => 150.0]
            ]);

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with(
                $this->stringContains('SELECT success, duration FROM circuit_breaker_metrics'),
                $this->anything()
            )
            ->willReturn($result);

        // Set environment variable for slow call threshold
        $_ENV['CIRCUIT_BREAKER_SLOW_CALL_THRESHOLD'] = '200';

        $metrics = $this->storage->getMetricsSnapshot('test', 60);

        $this->assertEquals(5, $metrics->getTotalCalls());
        $this->assertEquals(3, $metrics->getSuccessCalls());
        $this->assertEquals(2, $metrics->getFailedCalls());
        $this->assertEquals(3, $metrics->getSlowCalls()); // 200ms, 300ms, 250ms
        $this->assertEquals(200.0, $metrics->getAvgResponseTime());

        // Clean up
        unset($_ENV['CIRCUIT_BREAKER_SLOW_CALL_THRESHOLD']);
    }
    
    public function testGetMetricsSnapshot_withAggregatedData(): void
    {
        $this->setupTableExists();

        $result = $this->createMock(Result::class);
        $result->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn([
                'total_calls' => 100,
                'success_calls' => 70,
                'failed_calls' => 20,
                'slow_calls' => 10,
                'avg_response_time' => 150.5
            ]);

        $this->connection->expects($this->once())
            ->method('executeQuery')
            ->with(
                $this->stringContains('COUNT(*) as total_calls'),
                $this->anything()
            )
            ->willReturn($result);

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
                $this->stringContains('INSERT INTO circuit_breaker_locks'),
                [
                    'name' => 'test',
                    'token' => 'token123',
                    'expires_at' => $this->anything()
                ]
            )
            ->willReturn(1);

        $result = $this->storage->acquireLock('test', 'token123', 5);

        $this->assertTrue($result);
    }
    
    public function testAcquireLock_alreadyLocked(): void
    {
        $this->setupTableExists();

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->willThrowException(new \Exception('Duplicate entry'));

        $result = $this->storage->acquireLock('test', 'token123', 5);

        $this->assertFalse($result);
    }
    
    public function testReleaseLock(): void
    {
        $this->setupTableExists();

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('DELETE FROM circuit_breaker_locks'),
                [
                    'name' => 'test',
                    'token' => 'token123'
                ]
            )
            ->willReturn(1);

        $result = $this->storage->releaseLock('test', 'token123');

        $this->assertTrue($result);
    }
    
    public function testIsAvailable(): void
    {
        $this->connection->expects($this->once())
            ->method('fetchOne')
            ->with('SELECT 1')
            ->willReturn(1);

        $this->assertTrue($this->storage->isAvailable());
    }
    
    public function testIsAvailable_connectionFails(): void
    {
        $this->connection->expects($this->once())
            ->method('fetchOne')
            ->with('SELECT 1')
            ->willThrowException(new \Exception('Connection failed'));

        $this->assertFalse($this->storage->isAvailable());
    }
    
    public function testGetAllCircuitNames(): void
    {
        $this->setupTableExists();

        $this->connection->expects($this->once())
            ->method('fetchFirstColumn')
            ->with($this->stringContains('SELECT DISTINCT name FROM circuit_breaker_state'))
            ->willReturn(['circuit1', 'circuit2', 'circuit3']);

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
            });

        $this->storage->deleteCircuit('test');
    }
    
    public function testCleanupOldData(): void
    {
        $this->setupTableExists();

        // Should be called automatically on recordCall
        $matcher = $this->exactly(2);
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
                }
                return 1;
            });

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
            $result = $this->createMock(Result::class);
            $result->method('fetchAssociative')
                ->willReturn([
                    'state' => $testCase['state'],
                    'timestamp' => time(),
                    'attempt_count' => 0
                ]);
            $results[] = $result;
        }

        $this->connection->expects($this->exactly(3))
            ->method('executeQuery')
            ->willReturnOnConsecutiveCalls(...$results);

        foreach ($states as $testCase) {
            $state = $this->storage->getState('test' . $callCount);
            $this->assertEquals($testCase['expected'], $state->getState(),
                "Failed for state: {$testCase['state']}");
            $callCount++;
        }
    }
    
    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->schemaManager = $this->createMock(AbstractSchemaManager::class);

        $this->connection->expects($this->any())
            ->method('createSchemaManager')
            ->willReturn($this->schemaManager);

        $this->storage = new DoctrineStorage($this->connection, new NullLogger());
    }
}