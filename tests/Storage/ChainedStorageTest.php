<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Tourze\Symfony\CircuitBreaker\Enum\CircuitState;
use Tourze\Symfony\CircuitBreaker\Model\CallResult;
use Tourze\Symfony\CircuitBreaker\Model\CircuitBreakerState;
use Tourze\Symfony\CircuitBreaker\Storage\ChainedStorage;
use Tourze\Symfony\CircuitBreaker\Storage\DoctrineStorage;
use Tourze\Symfony\CircuitBreaker\Storage\MemoryStorage;
use Tourze\Symfony\CircuitBreaker\Storage\RedisAtomicStorage;

/**
 * @internal
 */
#[CoversClass(ChainedStorage::class)]
final class ChainedStorageTest extends TestCase
{
    private ChainedStorage $storage;

    private RedisAtomicStorage $redisStorage;

    private DoctrineStorage $doctrineStorage;

    private MemoryStorage $memoryStorage;

    private LoggerInterface $logger;

    public function testGetStateFromPrimaryStorage(): void
    {
        $expectedState = new CircuitBreakerState(CircuitState::OPEN);

        $this->redisStorage->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true)
        ;

        $this->redisStorage->expects($this->once())
            ->method('getState')
            ->with('test')
            ->willReturn($expectedState)
        ;

        $this->doctrineStorage->expects($this->never())
            ->method('getState')
        ;

        $state = $this->storage->getState('test');

        $this->assertEquals($expectedState, $state);
    }

    public function testGetStateFailoverToSecondary(): void
    {
        $expectedState = new CircuitBreakerState(CircuitState::HALF_OPEN);

        $this->redisStorage->expects($this->once())
            ->method('isAvailable')
            ->willReturn(false)
        ;

        $this->doctrineStorage->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true)
        ;

        $this->doctrineStorage->expects($this->once())
            ->method('getState')
            ->with('test')
            ->willReturn($expectedState)
        ;

        $state = $this->storage->getState('test');

        $this->assertEquals($expectedState, $state);
    }

    public function testGetStateAllStoragesFailReturnsDefault(): void
    {
        $this->redisStorage->expects($this->once())
            ->method('isAvailable')
            ->willReturn(false)
        ;

        $this->doctrineStorage->expects($this->once())
            ->method('isAvailable')
            ->willReturn(false)
        ;

        // Memory storage is always healthy, so it will get the state
        $this->memoryStorage->expects($this->once())
            ->method('getState')
            ->with('test')
            ->willReturn(new CircuitBreakerState())
        ;

        $state = $this->storage->getState('test');

        $this->assertTrue($state->isClosed());
    }

    public function testSaveStateSuccessOnPrimary(): void
    {
        $state = new CircuitBreakerState(CircuitState::OPEN);

        $this->redisStorage->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true)
        ;

        $this->redisStorage->expects($this->once())
            ->method('saveState')
            ->with('test', $state)
            ->willReturn(true)
        ;

        $result = $this->storage->saveState('test', $state);

        $this->assertTrue($result);
    }

    public function testSaveStatePrimaryFailsButSecondarySucceeds(): void
    {
        $state = new CircuitBreakerState(CircuitState::OPEN);

        $this->redisStorage->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true)
        ;

        $this->redisStorage->expects($this->once())
            ->method('saveState')
            ->with('test', $state)
            ->willThrowException(new \Exception('Primary storage error'))
        ;

        $this->doctrineStorage->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true)
        ;

        $this->doctrineStorage->expects($this->once())
            ->method('saveState')
            ->with('test', $state)
            ->willReturn(true)
        ;

        $result = $this->storage->saveState('test', $state);

        $this->assertTrue($result);
    }

    public function testRecordCallWithFailover(): void
    {
        $callResult = new CallResult(true, 100.0, time());

        // Primary fails
        $this->redisStorage->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true)
        ;

        $this->redisStorage->expects($this->once())
            ->method('recordCall')
            ->willThrowException(new \Exception('Write error'))
        ;

        // Secondary succeeds
        $this->doctrineStorage->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true)
        ;

        $this->doctrineStorage->expects($this->once())
            ->method('recordCall')
            ->with('test', $callResult)
        ;

        $this->storage->recordCall('test', $callResult);
    }

    public function testAcquireLockRetryOnFailure(): void
    {
        // First attempt fails
        $this->redisStorage->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true)
        ;

        $this->redisStorage->expects($this->once())
            ->method('acquireLock')
            ->with('test', 'token1', 5)
            ->willReturn(false)
        ;

        // Should not try secondary for lock operations
        $this->doctrineStorage->expects($this->never())
            ->method('acquireLock')
        ;

        $result = $this->storage->acquireLock('test', 'token1', 5);

        $this->assertFalse($result);
    }

    public function testIsAvailableAtLeastOneStorageAvailable(): void
    {
        $this->redisStorage->expects($this->once())
            ->method('isAvailable')
            ->willReturn(false)
        ;

        $this->doctrineStorage->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true)
        ;

        $this->assertTrue($this->storage->isAvailable());
    }

    public function testIsAvailableAllStoragesUnavailable(): void
    {
        $this->redisStorage->expects($this->once())
            ->method('isAvailable')
            ->willReturn(false)
        ;

        $this->doctrineStorage->expects($this->once())
            ->method('isAvailable')
            ->willReturn(false)
        ;

        // Memory storage is always available in our implementation
        $this->assertTrue($this->storage->isAvailable());
    }

    public function testGetActiveStorageType(): void
    {
        $this->redisStorage->expects($this->once())
            ->method('isAvailable')
            ->willReturn(false)
        ;

        $this->doctrineStorage->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true)
        ;

        // Force a read operation to set active storage
        $this->doctrineStorage->expects($this->once())
            ->method('getState')
            ->willReturn(new CircuitBreakerState())
        ;

        $this->storage->getState('test');

        $this->assertEquals('doctrine', $this->storage->getActiveStorageType());
    }

    public function testDeleteCircuitCascadestoAllStorages(): void
    {
        // All storages should be called regardless of availability
        $this->redisStorage->expects($this->once())
            ->method('deleteCircuit')
            ->with('test')
        ;

        $this->doctrineStorage->expects($this->once())
            ->method('deleteCircuit')
            ->with('test')
        ;

        $this->memoryStorage->expects($this->once())
            ->method('deleteCircuit')
            ->with('test')
        ;

        $this->storage->deleteCircuit('test');
    }

    public function testGetAllCircuitNamesMergesFromAllAvailableStorages(): void
    {
        $this->redisStorage->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true)
        ;

        $this->redisStorage->expects($this->once())
            ->method('getAllCircuitNames')
            ->willReturn(['circuit1', 'circuit2'])
        ;

        $this->doctrineStorage->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true)
        ;

        $this->doctrineStorage->expects($this->once())
            ->method('getAllCircuitNames')
            ->willReturn(['circuit2', 'circuit3'])
        ;

        // Memory storage is always available, so it will be called
        $this->memoryStorage->expects($this->once())
            ->method('getAllCircuitNames')
            ->willReturn([])
        ;

        $names = $this->storage->getAllCircuitNames();

        $this->assertCount(3, $names);
        $this->assertContains('circuit1', $names);
        $this->assertContains('circuit2', $names);
        $this->assertContains('circuit3', $names);
    }

    public function testFailureTrackingDisablesFailedStorage(): void
    {
        // Make Redis fail multiple times to reach MAX_FAILURES threshold
        $this->redisStorage->expects($this->exactly(3))
            ->method('isAvailable')
            ->willReturn(true)
        ;

        $this->redisStorage->expects($this->exactly(3))
            ->method('getState')
            ->willThrowException(new \Exception('Storage error'))
        ;

        // Should fallback to secondary for first 3 calls
        $this->doctrineStorage->expects($this->exactly(4))
            ->method('isAvailable')
            ->willReturn(true)
        ;

        $this->doctrineStorage->expects($this->exactly(4))
            ->method('getState')
            ->willReturn(new CircuitBreakerState())
        ;

        // Make 3 calls to reach MAX_FAILURES (which is 3)
        $this->storage->getState('test1');
        $this->storage->getState('test2');
        $this->storage->getState('test3');

        // Fourth call - Redis should be skipped due to failures
        $this->storage->getState('test4');
    }

    public function testReleaseLockCallsActiveStorage(): void
    {
        $token = 'test-token';

        $this->redisStorage->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true)
        ;

        $this->redisStorage->expects($this->once())
            ->method('releaseLock')
            ->with('test', $token)
            ->willReturn(true)
        ;

        $result = $this->storage->releaseLock('test', $token);

        $this->assertTrue($result);
    }

    public function testReleaseLockWithFailoverToSecondaryStorage(): void
    {
        $token = 'test-token';

        $this->redisStorage->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true)
        ;

        $this->redisStorage->expects($this->once())
            ->method('releaseLock')
            ->with('test', $token)
            ->willThrowException(new \RuntimeException('Redis error'))
        ;

        $this->doctrineStorage->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true)
        ;

        $this->doctrineStorage->expects($this->once())
            ->method('releaseLock')
            ->with('test', $token)
            ->willReturn(true)
        ;

        $result = $this->storage->releaseLock('test', $token);

        $this->assertTrue($result);
    }

    public function testReleaseLockReturnsDefaultWhenAllStoragesFail(): void
    {
        $token = 'test-token';

        // Redis fails
        $this->redisStorage->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true)
        ;

        $this->redisStorage->expects($this->once())
            ->method('releaseLock')
            ->willThrowException(new \RuntimeException('Redis error'))
        ;

        // Doctrine fails
        $this->doctrineStorage->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true)
        ;

        $this->doctrineStorage->expects($this->once())
            ->method('releaseLock')
            ->willThrowException(new \RuntimeException('Doctrine error'))
        ;

        // Memory fails (MemoryStorage doesn't call isAvailable in isStorageHealthy)
        $this->memoryStorage->expects($this->once())
            ->method('releaseLock')
            ->willThrowException(new \RuntimeException('Memory error'))
        ;

        $result = $this->storage->releaseLock('test', $token);

        $this->assertFalse($result); // Should return default value false
    }

    protected function setUp(): void
    {
        parent::setUp();

        // 在测试中使用 createMock() 对具体类 RedisAtomicStorage 进行 Mock
        // 理由1：RedisAtomicStorage 是项目中的具体存储实现类，没有对应的接口
        // 理由2：测试重点是 ChainedStorage 的链式存储逻辑，而不是具体存储的实现
        // 理由3：Mock 具体存储类可以精确控制每个存储层的行为，便于测试故障转移逻辑
        $this->redisStorage = $this->createMock(RedisAtomicStorage::class);
        // 在测试中使用 createMock() 对具体类 DoctrineStorage 进行 Mock
        // 理由1：DoctrineStorage 是项目中的具体存储实现类，没有对应的接口
        // 理由2：测试重点是 ChainedStorage 的链式存储逻辑，而不是具体存储的实现
        // 理由3：Mock 具体存储类可以精确控制每个存储层的行为，便于测试故障转移逻辑
        $this->doctrineStorage = $this->createMock(DoctrineStorage::class);
        // 在测试中使用 createMock() 对具体类 MemoryStorage 进行 Mock
        // 理由1：MemoryStorage 是项目中的具体存储实现类，没有对应的接口
        // 理由2：测试重点是 ChainedStorage 的链式存储逻辑，而不是具体存储的实现
        // 理由3：Mock 具体存储类可以精确控制每个存储层的行为，便于测试故障转移逻辑
        $this->memoryStorage = $this->createMock(MemoryStorage::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->storage = new ChainedStorage(
            $this->redisStorage,
            $this->doctrineStorage,
            $this->memoryStorage,
            $this->logger
        );
    }
}
