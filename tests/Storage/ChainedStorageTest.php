<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tourze\PHPUnitSymfonyKernelTest\AbstractIntegrationTestCase;
use Tourze\Symfony\CircuitBreaker\Enum\CircuitState;
use Tourze\Symfony\CircuitBreaker\Model\CallResult;
use Tourze\Symfony\CircuitBreaker\Model\CircuitBreakerState;
use Tourze\Symfony\CircuitBreaker\Storage\ChainedStorage;
use Tourze\Symfony\CircuitBreaker\Storage\MemoryStorage;

/**
 * @internal
 */
#[CoversClass(ChainedStorage::class)]
#[RunTestsInSeparateProcesses]
final class ChainedStorageTest extends AbstractIntegrationTestCase
{
    private ChainedStorage $storage;

    private MemoryStorage $memoryStorage;

    public function testSaveState(): void
    {
        $state = new CircuitBreakerState(CircuitState::OPEN);

        $result = $this->storage->saveState('test-memory', $state);
        $this->assertTrue($result);

        $retrievedState = $this->storage->getState('test-memory');
        $this->assertTrue($retrievedState->isOpen());
    }

    public function testAcquireLock(): void
    {
        $token = 'test-token-' . uniqid();

        $acquired = $this->storage->acquireLock('lock-test', $token, 5);
        $this->assertTrue($acquired);

        $acquiredAgain = $this->storage->acquireLock('lock-test', 'another-token', 5);
        $this->assertFalse($acquiredAgain);

        $released = $this->storage->releaseLock('lock-test', $token);
        $this->assertTrue($released);

        $acquiredAfterRelease = $this->storage->acquireLock('lock-test', 'new-token', 5);
        $this->assertTrue($acquiredAfterRelease);
    }

    public function testGetStateReturnsDefaultWhenNoStorageHasData(): void
    {
        $state = $this->storage->getState('non-existent');

        $this->assertTrue($state->isClosed());
        $this->assertEquals(0, $state->getAttemptCount());
    }

    public function testSaveAndGetStateWorkflow(): void
    {
        $state = new CircuitBreakerState(CircuitState::HALF_OPEN);

        $saveResult = $this->storage->saveState('workflow-test', $state);
        $this->assertTrue($saveResult);

        $retrievedState = $this->storage->getState('workflow-test');
        $this->assertEquals(CircuitState::HALF_OPEN, $retrievedState->getState());
    }

    public function testRecordCallPersistsData(): void
    {
        $callResult = new CallResult(true, 100.0, time());

        $this->storage->recordCall('call-test', $callResult);

        $metrics = $this->storage->getMetricsSnapshot('call-test', 60);
        $this->assertEquals(1, $metrics->getTotalCalls());
        $this->assertEquals(1, $metrics->getSuccessCalls());
    }

    public function testRecordMultipleCalls(): void
    {
        $successResult = new CallResult(true, 50.0, time());
        $failureResult = new CallResult(false, 200.0, time());

        $this->storage->recordCall('multi-call', $successResult);
        $this->storage->recordCall('multi-call', $failureResult);

        $metrics = $this->storage->getMetricsSnapshot('multi-call', 60);
        $this->assertEquals(2, $metrics->getTotalCalls());
        $this->assertEquals(1, $metrics->getSuccessCalls());
        $this->assertEquals(1, $metrics->getFailedCalls());
    }

    public function testReleaseLockWithWrongTokenFails(): void
    {
        $token = 'correct-token-' . uniqid();

        $this->storage->acquireLock('lock-test-2', $token, 5);

        $released = $this->storage->releaseLock('lock-test-2', 'wrong-token');
        $this->assertFalse($released);
    }

    public function testIsAvailableReturnsTrue(): void
    {
        $this->assertTrue($this->storage->isAvailable());
    }

    public function testGetAllCircuitNames(): void
    {
        $state1 = new CircuitBreakerState(CircuitState::OPEN);
        $state2 = new CircuitBreakerState(CircuitState::CLOSED);

        $this->storage->saveState('circuit-1', $state1);
        $this->storage->saveState('circuit-2', $state2);

        $names = $this->storage->getAllCircuitNames();

        $this->assertContains('circuit-1', $names);
        $this->assertContains('circuit-2', $names);
    }

    public function testDeleteCircuitRemovesData(): void
    {
        $state = new CircuitBreakerState(CircuitState::OPEN);
        $this->storage->saveState('to-delete', $state);

        $this->storage->deleteCircuit('to-delete');

        $retrievedState = $this->storage->getState('to-delete');
        $this->assertTrue($retrievedState->isClosed());
    }

    public function testGetActiveStorageType(): void
    {
        $state = new CircuitBreakerState(CircuitState::OPEN);
        $this->storage->saveState('active-test', $state);

        $activeType = $this->storage->getActiveStorageType();

        $this->assertContains($activeType, ['redis', 'doctrine', 'memory']);
    }

    public function testActiveStorageIsSetAfterOperation(): void
    {
        $this->storage->getState('test-active');

        $activeStorage = $this->storage->getActiveStorage();
        $this->assertNotNull($activeStorage);
    }

    public function testMetricsWindowSize(): void
    {
        $oldCall = new CallResult(true, 100.0, time() - 120);
        $recentCall = new CallResult(true, 50.0, time());

        $this->storage->recordCall('window-test', $oldCall);
        $this->storage->recordCall('window-test', $recentCall);

        $metrics = $this->storage->getMetricsSnapshot('window-test', 60);

        $this->assertGreaterThanOrEqual(1, $metrics->getTotalCalls());
    }

    public function testChainedStorageFailoverBehavior(): void
    {
        $state = new CircuitBreakerState(CircuitState::OPEN);

        $this->storage->saveState('failover-test', $state);

        $retrievedState = $this->storage->getState('failover-test');
        $this->assertTrue($retrievedState->isOpen());

        $activeType = $this->storage->getActiveStorageType();
        $this->assertNotEquals('none', $activeType);
    }

    public function testMultipleCircuitsIndependence(): void
    {
        $openState = new CircuitBreakerState(CircuitState::OPEN);
        $closedState = new CircuitBreakerState(CircuitState::CLOSED);

        $this->storage->saveState('circuit-a', $openState);
        $this->storage->saveState('circuit-b', $closedState);

        $stateA = $this->storage->getState('circuit-a');
        $stateB = $this->storage->getState('circuit-b');

        $this->assertTrue($stateA->isOpen());
        $this->assertTrue($stateB->isClosed());
    }

    public function testAverageResponseTimeCalculation(): void
    {
        $call1 = new CallResult(true, 100.0, time());
        $call2 = new CallResult(true, 200.0, time());

        $this->storage->recordCall('avg-test', $call1);
        $this->storage->recordCall('avg-test', $call2);

        $metrics = $this->storage->getMetricsSnapshot('avg-test', 60);

        $expectedAvg = (100.0 + 200.0) / 2;
        $this->assertEquals($expectedAvg, $metrics->getAvgResponseTime());
    }

    protected function onSetUp(): void
    {
        $this->memoryStorage = new MemoryStorage();
        $this->memoryStorage->clear();

        $this->storage = self::getService(ChainedStorage::class);
    }
}
