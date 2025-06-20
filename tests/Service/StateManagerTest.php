<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Tourze\Symfony\CircuitBreaker\Enum\CircuitState;
use Tourze\Symfony\CircuitBreaker\Event\CircuitClosedEvent;
use Tourze\Symfony\CircuitBreaker\Event\CircuitHalfOpenEvent;
use Tourze\Symfony\CircuitBreaker\Event\CircuitOpenedEvent;
use Tourze\Symfony\CircuitBreaker\Model\CircuitBreakerState;
use Tourze\Symfony\CircuitBreaker\Service\StateManager;
use Tourze\Symfony\CircuitBreaker\Storage\CircuitBreakerStorageInterface;

class StateManagerTest extends TestCase
{
    private StateManager $stateManager;
    private CircuitBreakerStorageInterface $storage;
    private EventDispatcherInterface $eventDispatcher;
    private LoggerInterface $logger;
    
    public function testGetState_usesCache(): void
    {
        $expectedState = new CircuitBreakerState(CircuitState::OPEN);

        $this->storage->expects($this->once())
            ->method('getState')
            ->with('test')
            ->willReturn($expectedState);

        // First call - loads from storage
        $state1 = $this->stateManager->getState('test');
        $this->assertEquals($expectedState, $state1);

        // Second call within cache TTL - should use cache
        $state2 = $this->stateManager->getState('test');
        $this->assertEquals($expectedState, $state2);
    }
    
    public function testSetOpen_triggersEvent(): void
    {
        $this->storage->expects($this->once())
            ->method('acquireLock')
            ->with('test', $this->anything(), 5)
            ->willReturn(true);

        $this->storage->expects($this->once())
            ->method('saveState')
            ->with('test', $this->callback(function ($state) {
                return $state->isOpen();
            }))
            ->willReturn(true);

        $this->storage->expects($this->once())
            ->method('releaseLock')
            ->willReturn(true);

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($event) {
                return $event instanceof CircuitOpenedEvent &&
                       $event->getCircuitName() === 'test' &&
                       $event->getFailureRate() === 75.5;
            }));

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Circuit breaker opened',
                ['circuit' => 'test', 'failure_rate' => 75.5]
            );

        $this->stateManager->setOpen('test', 75.5);
    }
    
    public function testSetHalfOpen_triggersEvent(): void
    {
        $this->storage->expects($this->once())
            ->method('acquireLock')
            ->willReturn(true);

        $this->storage->expects($this->once())
            ->method('saveState')
            ->with('test', $this->callback(function ($state) {
                return $state->isHalfOpen();
            }))
            ->willReturn(true);

        $this->storage->expects($this->once())
            ->method('releaseLock')
            ->willReturn(true);

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(CircuitHalfOpenEvent::class));

        $this->stateManager->setHalfOpen('test');
    }
    
    public function testSetClosed_triggersEvent(): void
    {
        $this->storage->expects($this->once())
            ->method('acquireLock')
            ->willReturn(true);

        $this->storage->expects($this->once())
            ->method('saveState')
            ->with('test', $this->callback(function ($state) {
                return $state->isClosed();
            }))
            ->willReturn(true);

        $this->storage->expects($this->once())
            ->method('releaseLock')
            ->willReturn(true);

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(CircuitClosedEvent::class));

        $this->stateManager->setClosed('test');
    }
    
    public function testCheckForHalfOpenTransition_whenTimeElapsed(): void
    {
        $openState = new CircuitBreakerState(CircuitState::OPEN, time() - 100);

        $this->storage->expects($this->once())
            ->method('getState')
            ->willReturn($openState);

        // Should trigger transition to half-open
        $this->storage->expects($this->once())
            ->method('acquireLock')
            ->willReturn(true);

        $this->storage->expects($this->once())
            ->method('saveState')
            ->with('test', $this->callback(function ($state) {
                return $state->isHalfOpen();
            }))
            ->willReturn(true);

        $result = $this->stateManager->checkForHalfOpenTransition('test', 60);

        $this->assertTrue($result);
    }
    
    public function testCheckForHalfOpenTransition_whenTimeNotElapsed(): void
    {
        $openState = new CircuitBreakerState(CircuitState::OPEN, time() - 30);

        $this->storage->expects($this->once())
            ->method('getState')
            ->willReturn($openState);

        // Should not trigger transition
        $this->storage->expects($this->never())
            ->method('acquireLock');

        $result = $this->stateManager->checkForHalfOpenTransition('test', 60);

        $this->assertFalse($result);
    }
    
    public function testIncrementAttemptCount(): void
    {
        $state = new CircuitBreakerState(CircuitState::HALF_OPEN);

        $this->storage->expects($this->once())
            ->method('acquireLock')
            ->willReturn(true);

        $this->storage->expects($this->once())
            ->method('getState')
            ->willReturn($state);

        $this->storage->expects($this->once())
            ->method('saveState')
            ->with('test', $this->callback(function ($state) {
                return $state->getAttemptCount() === 1;
            }))
            ->willReturn(true);

        $this->storage->expects($this->once())
            ->method('releaseLock')
            ->willReturn(true);

        $this->stateManager->incrementAttemptCount('test');
    }
    
    public function testResetCircuit(): void
    {
        $this->storage->expects($this->once())
            ->method('saveState')
            ->with('test', $this->callback(function ($state) {
                return $state->isClosed() && $state->getAttemptCount() === 0;
            }))
            ->willReturn(true);

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Circuit breaker reset', ['circuit' => 'test']);

        $this->stateManager->resetCircuit('test');
    }
    
    public function testForceOpen(): void
    {
        // Should delegate to setOpen
        $this->storage->expects($this->once())
            ->method('acquireLock')
            ->willReturn(true);

        $this->storage->expects($this->once())
            ->method('saveState')
            ->willReturn(true);

        $this->storage->expects($this->once())
            ->method('releaseLock')
            ->willReturn(true);

        $this->logger->expects($this->exactly(2))
            ->method($this->anything());

        $this->stateManager->forceOpen('test');
    }
    
    public function testForceClose(): void
    {
        // Should delegate to setClosed
        $this->storage->expects($this->once())
            ->method('acquireLock')
            ->willReturn(true);

        $this->storage->expects($this->once())
            ->method('saveState')
            ->willReturn(true);

        $this->storage->expects($this->once())
            ->method('releaseLock')
            ->willReturn(true);

        $this->logger->expects($this->exactly(2))
            ->method('info');

        $this->stateManager->forceClose('test');
    }
    
    public function testLockFailure_logsWarning(): void
    {
        $this->storage->expects($this->once())
            ->method('acquireLock')
            ->willReturn(false);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Failed to acquire lock for state transition',
                ['circuit' => 'test', 'state' => 'open']
            );

        $this->stateManager->setOpen('test');
    }
    
    public function testSaveFailure_logsError(): void
    {
        $this->storage->expects($this->once())
            ->method('acquireLock')
            ->willReturn(true);

        $this->storage->expects($this->once())
            ->method('saveState')
            ->willReturn(false);

        $this->storage->expects($this->once())
            ->method('releaseLock')
            ->willReturn(true);

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Failed to save circuit breaker state',
                ['circuit' => 'test', 'state' => 'open']
            );

        $this->stateManager->setOpen('test');
    }
    
    protected function setUp(): void
    {
        $this->storage = $this->createMock(CircuitBreakerStorageInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->stateManager = new StateManager(
            $this->storage,
            $this->eventDispatcher,
            $this->logger
        );
    }
}