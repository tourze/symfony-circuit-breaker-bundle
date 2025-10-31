<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Tourze\PHPUnitSymfonyKernelTest\AbstractIntegrationTestCase;
use Tourze\Symfony\CircuitBreaker\Enum\CircuitState;
use Tourze\Symfony\CircuitBreaker\Event\CircuitClosedEvent;
use Tourze\Symfony\CircuitBreaker\Event\CircuitHalfOpenEvent;
use Tourze\Symfony\CircuitBreaker\Event\CircuitOpenedEvent;
use Tourze\Symfony\CircuitBreaker\Model\CircuitBreakerState;
use Tourze\Symfony\CircuitBreaker\Service\StateManager;
use Tourze\Symfony\CircuitBreaker\Storage\CircuitBreakerStorageInterface;

/**
 * @internal
 */
#[CoversClass(StateManager::class)]
#[RunTestsInSeparateProcesses]
final class StateManagerTest extends AbstractIntegrationTestCase
{
    private StateManager $stateManager;

    private CircuitBreakerStorageInterface $storage;

    private EventDispatcherInterface $eventDispatcher;

    private LoggerInterface $logger;

    public function testGetStateUsesCache(): void
    {
        $expectedState = new CircuitBreakerState(CircuitState::OPEN);

        $this->storage->expects($this->once())
            ->method('getState')
            ->with('test')
            ->willReturn($expectedState)
        ;

        // First call - loads from storage
        $state1 = $this->stateManager->getState('test');
        $this->assertEquals($expectedState, $state1);

        // Second call within cache TTL - should use cache
        $state2 = $this->stateManager->getState('test');
        $this->assertEquals($expectedState, $state2);
    }

    public function testSetOpenTriggersEvent(): void
    {
        $this->storage->expects($this->once())
            ->method('acquireLock')
            ->with('test', self::anything(), 5)
            ->willReturn(true)
        ;

        $this->storage->expects($this->once())
            ->method('saveState')
            ->with('test', self::callback(function ($state) {
                return $state->isOpen();
            }))
            ->willReturn(true)
        ;

        $this->storage->expects($this->once())
            ->method('releaseLock')
            ->willReturn(true)
        ;

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with(self::callback(function ($event) {
                return $event instanceof CircuitOpenedEvent
                       && 'test' === $event->getCircuitName()
                       && 75.5 === $event->getFailureRate();
            }))
        ;

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Circuit breaker opened',
                ['circuit' => 'test', 'failure_rate' => 75.5]
            )
        ;

        $this->stateManager->setOpen('test', 75.5);
    }

    public function testSetHalfOpenTriggersEvent(): void
    {
        $this->storage->expects($this->once())
            ->method('acquireLock')
            ->willReturn(true)
        ;

        $this->storage->expects($this->once())
            ->method('saveState')
            ->with('test', self::callback(function ($state) {
                return $state->isHalfOpen();
            }))
            ->willReturn(true)
        ;

        $this->storage->expects($this->once())
            ->method('releaseLock')
            ->willReturn(true)
        ;

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with(self::isInstanceOf(CircuitHalfOpenEvent::class))
        ;

        $this->stateManager->setHalfOpen('test');
    }

    public function testSetClosedTriggersEvent(): void
    {
        $this->storage->expects($this->once())
            ->method('acquireLock')
            ->willReturn(true)
        ;

        $this->storage->expects($this->once())
            ->method('saveState')
            ->with('test', self::callback(function ($state) {
                return $state->isClosed();
            }))
            ->willReturn(true)
        ;

        $this->storage->expects($this->once())
            ->method('releaseLock')
            ->willReturn(true)
        ;

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with(self::isInstanceOf(CircuitClosedEvent::class))
        ;

        $this->stateManager->setClosed('test');
    }

    public function testCheckForHalfOpenTransitionWhenTimeElapsed(): void
    {
        $openState = new CircuitBreakerState(CircuitState::OPEN, time() - 100);

        $this->storage->expects($this->once())
            ->method('getState')
            ->willReturn($openState)
        ;

        // Should trigger transition to half-open
        $this->storage->expects($this->once())
            ->method('acquireLock')
            ->willReturn(true)
        ;

        $this->storage->expects($this->once())
            ->method('saveState')
            ->with('test', self::callback(function ($state) {
                return $state->isHalfOpen();
            }))
            ->willReturn(true)
        ;

        $result = $this->stateManager->checkForHalfOpenTransition('test', 60);

        $this->assertTrue($result);
    }

    public function testCheckForHalfOpenTransitionWhenTimeNotElapsed(): void
    {
        $openState = new CircuitBreakerState(CircuitState::OPEN, time() - 30);

        $this->storage->expects($this->once())
            ->method('getState')
            ->willReturn($openState)
        ;

        // Should not trigger transition
        $this->storage->expects($this->never())
            ->method('acquireLock')
        ;

        $result = $this->stateManager->checkForHalfOpenTransition('test', 60);

        $this->assertFalse($result);
    }

    public function testIncrementAttemptCount(): void
    {
        $state = new CircuitBreakerState(CircuitState::HALF_OPEN);

        $this->storage->expects($this->once())
            ->method('acquireLock')
            ->willReturn(true)
        ;

        $this->storage->expects($this->once())
            ->method('getState')
            ->willReturn($state)
        ;

        $this->storage->expects($this->once())
            ->method('saveState')
            ->with('test', self::callback(function ($state) {
                return 1 === $state->getAttemptCount();
            }))
            ->willReturn(true)
        ;

        $this->storage->expects($this->once())
            ->method('releaseLock')
            ->willReturn(true)
        ;

        $this->stateManager->incrementAttemptCount('test');
    }

    public function testResetCircuit(): void
    {
        $this->storage->expects($this->once())
            ->method('saveState')
            ->with('test', self::callback(function ($state) {
                return $state->isClosed() && 0 === $state->getAttemptCount();
            }))
            ->willReturn(true)
        ;

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Circuit breaker reset', ['circuit' => 'test'])
        ;

        $this->stateManager->resetCircuit('test');
    }

    public function testForceOpen(): void
    {
        // Should delegate to setOpen
        $this->storage->expects($this->once())
            ->method('acquireLock')
            ->willReturn(true)
        ;

        $this->storage->expects($this->once())
            ->method('saveState')
            ->willReturn(true)
        ;

        $this->storage->expects($this->once())
            ->method('releaseLock')
            ->willReturn(true)
        ;

        $this->logger->expects($this->exactly(2))
            ->method(self::anything())
        ;

        $this->stateManager->forceOpen('test');
    }

    public function testForceClose(): void
    {
        // Should delegate to setClosed
        $this->storage->expects($this->once())
            ->method('acquireLock')
            ->willReturn(true)
        ;

        $this->storage->expects($this->once())
            ->method('saveState')
            ->willReturn(true)
        ;

        $this->storage->expects($this->once())
            ->method('releaseLock')
            ->willReturn(true)
        ;

        $this->logger->expects($this->exactly(2))
            ->method('info')
        ;

        $this->stateManager->forceClose('test');
    }

    public function testLockFailureLogsWarning(): void
    {
        $this->storage->expects($this->once())
            ->method('acquireLock')
            ->willReturn(false)
        ;

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Failed to acquire lock for state transition',
                ['circuit' => 'test', 'state' => 'open']
            )
        ;

        $this->stateManager->setOpen('test');
    }

    public function testSaveFailureLogsError(): void
    {
        $this->storage->expects($this->once())
            ->method('acquireLock')
            ->willReturn(true)
        ;

        $this->storage->expects($this->once())
            ->method('saveState')
            ->willReturn(false)
        ;

        $this->storage->expects($this->once())
            ->method('releaseLock')
            ->willReturn(true)
        ;

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Failed to save circuit breaker state',
                ['circuit' => 'test', 'state' => 'open']
            )
        ;

        $this->stateManager->setOpen('test');
    }

    protected function onSetUp(): void
    {
        $this->storage = $this->createMock(CircuitBreakerStorageInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // 将 Mock 对象设置到容器中
        $container = self::getContainer();
        $container->set('Tourze\Symfony\CircuitBreaker\Storage\CircuitBreakerStorageInterface', $this->storage);

        // StateManager 需要手动构建，因为：
        // 1. 测试在独立进程中运行 (#[RunTestsInSeparateProcesses])
        // 2. 需要 Mock 依赖进行行为验证
        // 3. 容器中的默认服务已经初始化，无法替换为 Mock
        // @phpstan-ignore-next-line 特殊的测试场景，需要Mock验证行为，无法从容器获取
        $this->stateManager = new StateManager($this->storage, $this->eventDispatcher, $this->logger);
        $container->set(StateManager::class, $this->stateManager);
    }
}
