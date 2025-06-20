<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Tourze\Symfony\CircuitBreaker\Enum\CircuitState;
use Tourze\Symfony\CircuitBreaker\Service\CircuitBreakerConfigService;
use Tourze\Symfony\CircuitBreaker\Service\CircuitBreakerService;
use Tourze\Symfony\CircuitBreaker\Service\MetricsCollector;
use Tourze\Symfony\CircuitBreaker\Service\StateManager;
use Tourze\Symfony\CircuitBreaker\Storage\MemoryStorage;
use Tourze\Symfony\CircuitBreaker\Strategy\StrategyManager;

/**
 * 测试并发场景和边缘情况
 */
class ConcurrencyTest extends TestCase
{
    private CircuitBreakerService $circuitBreaker;
    private MemoryStorage $storage;
    
    public function testConcurrentRequestsInClosedState(): void
    {
        $results = [];

        // Simulate 20 concurrent requests
        for ($i = 0; $i < 20; $i++) {
            $allowed = $this->circuitBreaker->isAllowed('test');
            $results[] = $allowed;

            // Half succeed, half fail
            if ($i < 10) {
                $this->circuitBreaker->recordSuccess('test', 50);
            } else {
                $this->circuitBreaker->recordFailure('test', new \RuntimeException('Test'), 50);
            }
        }

        // All should be allowed in closed state
        $this->assertCount(20, array_filter($results));

        // State should now be open (50% failure rate with 20 calls)
        $state = $this->storage->getState('test');
        $this->assertTrue($state->isOpen());
    }
    
    public function testConcurrentRequestsInHalfOpenState(): void
    {
        // Set to half-open state
        $state = new \Tourze\Symfony\CircuitBreaker\Model\CircuitBreakerState(CircuitState::HALF_OPEN);
        $this->storage->saveState('test', $state);

        $results = [];

        // Simulate 10 concurrent requests in half-open state
        for ($i = 0; $i < 10; $i++) {
            $allowed = $this->circuitBreaker->isAllowed('test');
            $results[] = $allowed;
        }

        // Only 5 should be allowed (permitted_number_of_calls_in_half_open_state)
        $allowedCount = count(array_filter($results));
        $this->assertEquals(5, $allowedCount);
    }
    
    public function testRaceConditionDuringStateTransition(): void
    {
        // Set up a state that's about to transition
        $state = new \Tourze\Symfony\CircuitBreaker\Model\CircuitBreakerState(
            CircuitState::OPEN,
            time() - 2 // Opened 2 seconds ago, wait time is 1 second
        );
        $this->storage->saveState('test', $state);

        $transitioned = false;
        $results = [];

        // Multiple concurrent requests trying to transition to half-open
        for ($i = 0; $i < 5; $i++) {
            $allowed = $this->circuitBreaker->isAllowed('test');
            $results[] = $allowed;

            $currentState = $this->storage->getState('test');
            if ($currentState->isHalfOpen()) {
                $transitioned = true;
            }
        }

        // Should have transitioned to half-open
        $this->assertTrue($transitioned);

        // At least one request should be allowed after transition
        $this->assertTrue(in_array(true, $results));
    }
    
    public function testHighVolumeRequests(): void
    {
        $startTime = microtime(true);

        // Simulate 1000 requests
        for ($i = 0; $i < 1000; $i++) {
            if ($this->circuitBreaker->isAllowed('high-volume')) {
                // 70% success rate
                if ($i % 10 < 7) {
                    $this->circuitBreaker->recordSuccess('high-volume', rand(10, 50));
                } else {
                    $this->circuitBreaker->recordFailure(
                        'high-volume',
                        new \RuntimeException('High volume test'),
                        rand(50, 200)
                    );
                }
            }
        }

        $endTime = microtime(true);
        $duration = $endTime - $startTime;

        // Should complete within reasonable time (< 1 second)
        $this->assertLessThan(1.0, $duration);

        // Check final metrics
        $metrics = $this->storage->getMetricsSnapshot('high-volume', 100);
        $this->assertGreaterThan(0, $metrics->getTotalCalls());
    }
    
    public function testMemoryLeakPrevention(): void
    {
        $initialMemory = memory_get_usage();

        // Create and delete many circuits
        for ($i = 0; $i < 100; $i++) {
            $circuitName = 'memory-test-' . $i;

            // Record some calls
            for ($j = 0; $j < 10; $j++) {
                $this->circuitBreaker->recordSuccess($circuitName, 50);
            }

            // Delete the circuit
            $this->storage->deleteCircuit($circuitName);
        }

        $finalMemory = memory_get_usage();
        $memoryIncrease = $finalMemory - $initialMemory;

        // Memory increase should be minimal (< 1MB)
        $this->assertLessThan(1024 * 1024, $memoryIncrease);
    }
    
    public function testExtremeFailureScenario(): void
    {
        // All requests fail
        for ($i = 0; $i < 50; $i++) {
            if ($this->circuitBreaker->isAllowed('extreme-failure')) {
                $this->circuitBreaker->recordFailure(
                    'extreme-failure',
                    new \RuntimeException('Total failure'),
                    100
                );
            }
        }

        $state = $this->storage->getState('extreme-failure');
        $this->assertTrue($state->isOpen());

        // Should not allow any requests
        $this->assertFalse($this->circuitBreaker->isAllowed('extreme-failure'));
    }
    
    public function testSlowCallDetection(): void
    {
        // Set slow call threshold via environment variable
        $_ENV['CIRCUIT_BREAKER_SLOW_CALL_THRESHOLD'] = '100';

        // Mix of fast and slow calls
        for ($i = 0; $i < 20; $i++) {
            if ($this->circuitBreaker->isAllowed('slow-detection')) {
                // Half are slow calls (> 100ms threshold)
                $duration = $i % 2 === 0 ? 150 : 50;
                $this->circuitBreaker->recordSuccess('slow-detection', $duration);
            }
        }

        $metrics = $this->storage->getMetricsSnapshot('slow-detection', 100);
        $this->assertEquals(10, $metrics->getSlowCalls());
        $this->assertEquals(50.0, $metrics->getSlowCallRate());

        // Clean up
        unset($_ENV['CIRCUIT_BREAKER_SLOW_CALL_THRESHOLD']);
    }
    
    public function testZeroCallsScenario(): void
    {
        // Get metrics for a circuit with no calls
        $metrics = $this->storage->getMetricsSnapshot('never-used', 60);

        $this->assertEquals(0, $metrics->getTotalCalls());
        $this->assertEquals(0.0, $metrics->getFailureRate());
        $this->assertEquals(0.0, $metrics->getSuccessRate());
        $this->assertEquals(0.0, $metrics->getSlowCallRate());
        $this->assertEquals(0.0, $metrics->getAvgResponseTime());
    }
    
    public function testVeryLongResponseTime(): void
    {
        // Test with extremely long response time
        $this->circuitBreaker->recordSuccess('long-response', 999999.99);

        $metrics = $this->storage->getMetricsSnapshot('long-response', 60);
        $this->assertEquals(999999.99, $metrics->getAvgResponseTime());
    }
    
    public function testRapidStateChanges(): void
    {
        $states = [];

        // Force rapid state changes
        for ($i = 0; $i < 5; $i++) {
            // Record failures to open
            for ($j = 0; $j < 15; $j++) {
                $this->circuitBreaker->recordFailure(
                    'rapid-change',
                    new \RuntimeException('Test'),
                    50
                );
            }

            $state = $this->storage->getState('rapid-change');
            $states[] = $state->getState()->value;

            // Wait for transition to half-open
            sleep(1);
            $this->circuitBreaker->isAllowed('rapid-change');

            $state = $this->storage->getState('rapid-change');
            $states[] = $state->getState()->value;

            // Record successes to close
            for ($j = 0; $j < 10; $j++) {
                $this->circuitBreaker->recordSuccess('rapid-change', 50);
            }

            $state = $this->storage->getState('rapid-change');
            $states[] = $state->getState()->value;
        }

        // Should see state transitions
        $this->assertContains('open', $states);
        $this->assertContains('half_open', $states);
        $this->assertContains('closed', $states);
    }
    
    protected function setUp(): void
    {
        $this->storage = new MemoryStorage();
        $configService = $this->createMock(CircuitBreakerConfigService::class);
        $configService->method('getCircuitConfig')
            ->willReturn([
                'failure_rate_threshold' => 50,
                'minimum_number_of_calls' => 10,
                'permitted_number_of_calls_in_half_open_state' => 5,
                'wait_duration_in_open_state' => 1, // 1 second for faster tests
                'sliding_window_size' => 100,
                'slow_call_duration_threshold' => 100,
                'slow_call_rate_threshold' => 50,
                'consecutive_failure_threshold' => 3,
                'ignore_exceptions' => [],
                'record_exceptions' => []
            ]);

        $eventDispatcher = new EventDispatcher();
        $logger = new NullLogger();

        $stateManager = new StateManager($this->storage, $eventDispatcher, $logger);
        $metricsCollector = new MetricsCollector($this->storage, $configService);
        $strategyManager = new StrategyManager($logger);

        $this->circuitBreaker = new CircuitBreakerService(
            $configService,
            $stateManager,
            $metricsCollector,
            $strategyManager,
            $eventDispatcher,
            $logger
        );
    }
}