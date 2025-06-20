<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Storage;

use PHPUnit\Framework\TestCase;
use Tourze\Symfony\CircuitBreaker\Enum\CircuitState;
use Tourze\Symfony\CircuitBreaker\Model\CallResult;
use Tourze\Symfony\CircuitBreaker\Model\CircuitBreakerState;
use Tourze\Symfony\CircuitBreaker\Storage\MemoryStorage;

class MemoryStorageTest extends TestCase
{
    private MemoryStorage $storage;
    
    public function testGetState_returnsDefaultState(): void
    {
        $state = $this->storage->getState('test');

        $this->assertInstanceOf(CircuitBreakerState::class, $state);
        $this->assertTrue($state->isClosed());
    }
    
    public function testSaveAndGetState(): void
    {
        $state = new CircuitBreakerState(CircuitState::OPEN);

        $this->assertTrue($this->storage->saveState('test', $state));

        $retrievedState = $this->storage->getState('test');
        $this->assertTrue($retrievedState->isOpen());
    }
    
    public function testRecordCall(): void
    {
        $result = new CallResult(
            success: true,
            duration: 100.0,
            timestamp: time()
        );

        $this->storage->recordCall('test', $result);

        $metrics = $this->storage->getMetricsSnapshot('test', 60);
        $this->assertEquals(1, $metrics->getTotalCalls());
        $this->assertEquals(1, $metrics->getSuccessCalls());
        $this->assertEquals(0, $metrics->getFailedCalls());
    }
    
    public function testGetMetricsSnapshot_withTimeWindow(): void
    {
        $now = time();

        // Add old call (outside window)
        $oldResult = new CallResult(
            success: true,
            duration: 50.0,
            timestamp: $now - 120
        );
        $this->storage->recordCall('test', $oldResult);

        // Add recent calls (inside window)
        $recentResult1 = new CallResult(
            success: true,
            duration: 100.0,
            timestamp: $now - 30
        );
        $this->storage->recordCall('test', $recentResult1);

        $recentResult2 = new CallResult(
            success: false,
            duration: 200.0,
            timestamp: $now - 10
        );
        $this->storage->recordCall('test', $recentResult2);

        $metrics = $this->storage->getMetricsSnapshot('test', 60);

        $this->assertEquals(2, $metrics->getTotalCalls());
        $this->assertEquals(1, $metrics->getSuccessCalls());
        $this->assertEquals(1, $metrics->getFailedCalls());
        $this->assertEquals(150.0, $metrics->getAvgResponseTime());
    }
    
    public function testGetAllCircuitNames(): void
    {
        $this->storage->saveState('circuit1', new CircuitBreakerState());
        $this->storage->saveState('circuit2', new CircuitBreakerState());
        $this->storage->recordCall('circuit3', new CallResult(true, 100.0, time()));

        $names = $this->storage->getAllCircuitNames();

        $this->assertCount(3, $names);
        $this->assertContains('circuit1', $names);
        $this->assertContains('circuit2', $names);
        $this->assertContains('circuit3', $names);
    }
    
    public function testDeleteCircuit(): void
    {
        $this->storage->saveState('test', new CircuitBreakerState(CircuitState::OPEN));
        $this->storage->recordCall('test', new CallResult(true, 100.0, time()));

        $this->storage->deleteCircuit('test');

        $state = $this->storage->getState('test');
        $this->assertTrue($state->isClosed()); // Should return default state

        $metrics = $this->storage->getMetricsSnapshot('test', 60);
        $this->assertEquals(0, $metrics->getTotalCalls());
    }
    
    public function testAcquireLock(): void
    {
        $this->assertTrue($this->storage->acquireLock('test', 'token1', 5));
        $this->assertFalse($this->storage->acquireLock('test', 'token2', 5));
    }
    
    public function testReleaseLock(): void
    {
        $this->storage->acquireLock('test', 'token1', 5);

        $this->assertTrue($this->storage->releaseLock('test', 'token1'));
        $this->assertFalse($this->storage->releaseLock('test', 'wrong-token'));

        // After release, should be able to acquire again
        $this->assertTrue($this->storage->acquireLock('test', 'token2', 5));
    }
    
    public function testIsAvailable(): void
    {
        $this->assertTrue($this->storage->isAvailable());
    }
    
    protected function setUp(): void
    {
        $this->storage = new MemoryStorage();
    }
    
    protected function tearDown(): void
    {
        $this->storage->clear();
    }
}