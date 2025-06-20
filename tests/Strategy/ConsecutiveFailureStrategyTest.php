<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Strategy;

use PHPUnit\Framework\TestCase;
use Tourze\Symfony\CircuitBreaker\Model\MetricsSnapshot;
use Tourze\Symfony\CircuitBreaker\Strategy\ConsecutiveFailureStrategy;

class ConsecutiveFailureStrategyTest extends TestCase
{
    private ConsecutiveFailureStrategy $strategy;
    
    public function testGetName(): void
    {
        $this->assertEquals('consecutive_failure', $this->strategy->getName());
    }
    
    public function testShouldOpen_withConsecutiveFailures(): void
    {
        $circuitName = 'test-circuit';
        $config = [
            'consecutive_failure_threshold' => 3
        ];

        // Record 3 consecutive failures
        $this->strategy->recordResult($circuitName, false);
        $this->strategy->recordResult($circuitName, false);
        $this->strategy->recordResult($circuitName, false);

        $metrics = new MetricsSnapshot(
            totalCalls: 3,
            failedCalls: 3,
            successCalls: 0
        );

        // Set current circuit name before checking
        $this->strategy->setCurrentCircuitName($circuitName);

        // Should open after 3 consecutive failures
        $this->assertTrue($this->strategy->shouldOpen($metrics, $config));
    }
    
    public function testShouldOpen_successResetsCounter(): void
    {
        $circuitName = 'test-circuit';
        $config = [
            'consecutive_failure_threshold' => 3
        ];

        // Record 2 failures
        $this->strategy->recordResult($circuitName, false);
        $this->strategy->recordResult($circuitName, false);

        // Record a success - should reset counter
        $this->strategy->recordResult($circuitName, true);

        // Record 2 more failures - should not open yet
        $this->strategy->recordResult($circuitName, false);
        $this->strategy->recordResult($circuitName, false);

        $metrics = new MetricsSnapshot(
            totalCalls: 5,
            failedCalls: 4,
            successCalls: 1
        );

        // Set current circuit name before checking
        $this->strategy->setCurrentCircuitName($circuitName);

        $this->assertFalse($this->strategy->shouldOpen($metrics, $config));
    }
    
    public function testShouldClose_alwaysReturnsTrue(): void
    {
        $metrics = new MetricsSnapshot(
            totalCalls: 10,
            failedCalls: 5,
            successCalls: 5
        );

        $config = ['consecutive_failure_threshold' => 3];

        // Any success should allow closing
        $this->assertTrue($this->strategy->shouldClose($metrics, $config));
    }
    
    public function testMultipleCircuits_independentCounters(): void
    {
        $config = [
            'consecutive_failure_threshold' => 2
        ];

        // Circuit 1: 2 failures - should open
        $this->strategy->recordResult('circuit1', false);
        $this->strategy->recordResult('circuit1', false);

        $metrics1 = new MetricsSnapshot(totalCalls: 2, failedCalls: 2);
        $this->strategy->setCurrentCircuitName('circuit1');
        $this->assertTrue($this->strategy->shouldOpen($metrics1, $config));

        // Circuit 2: 1 failure - should not open
        $this->strategy->recordResult('circuit2', false);

        $metrics2 = new MetricsSnapshot(totalCalls: 1, failedCalls: 1);
        $this->strategy->setCurrentCircuitName('circuit2');
        $this->assertFalse($this->strategy->shouldOpen($metrics2, $config));

        // Circuit 2: success then failure - counter reset, should not open
        $this->strategy->recordResult('circuit2', true);
        $this->strategy->recordResult('circuit2', false);

        $metrics2Updated = new MetricsSnapshot(totalCalls: 3, failedCalls: 2, successCalls: 1);
        $this->strategy->setCurrentCircuitName('circuit2');
        $this->assertFalse($this->strategy->shouldOpen($metrics2Updated, $config));
    }
    
    public function testShouldOpen_withMissingConfig_usesDefault(): void
    {
        $circuitName = 'test-circuit';
        $config = []; // Missing consecutive_failure_threshold

        // Default threshold is 5
        for ($i = 0; $i < 5; $i++) {
            $this->strategy->recordResult($circuitName, false);
            $metrics = new MetricsSnapshot(
                totalCalls: $i + 1,
                failedCalls: $i + 1
            );

            $this->strategy->setCurrentCircuitName($circuitName);

            if ($i < 4) {
                $this->assertFalse($this->strategy->shouldOpen($metrics, $config));
            } else {
                $this->assertTrue($this->strategy->shouldOpen($metrics, $config));
            }
        }
    }
    
    public function testReset_clearsAllCounters(): void
    {
        // Record failures for multiple circuits
        $this->strategy->recordResult('circuit1', false);
        $this->strategy->recordResult('circuit1', false);
        $this->strategy->recordResult('circuit2', false);

        // Reset all counters
        $this->strategy->reset();

        // Check that counters are cleared
        $this->assertEquals(0, $this->strategy->getConsecutiveFailures('circuit1'));
        $this->assertEquals(0, $this->strategy->getConsecutiveFailures('circuit2'));

        $config = ['consecutive_failure_threshold' => 1];
        $metrics = new MetricsSnapshot(totalCalls: 2, failedCalls: 2);

        $this->strategy->setCurrentCircuitName('circuit1');
        $this->assertFalse($this->strategy->shouldOpen($metrics, $config));
    }
    
    public function testShouldOpen_withoutSettingCircuitName_returnsFalse(): void
    {
        $config = [
            'consecutive_failure_threshold' => 1
        ];

        // Record failures
        $this->strategy->recordResult('circuit1', false);

        $metrics = new MetricsSnapshot(totalCalls: 1, failedCalls: 1);

        // Don't set current circuit name - should return false
        $this->assertFalse($this->strategy->shouldOpen($metrics, $config));
    }
    
    protected function setUp(): void
    {
        $this->strategy = new ConsecutiveFailureStrategy();
    }
}