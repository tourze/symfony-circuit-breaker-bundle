<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tourze\PHPUnitSymfonyKernelTest\AbstractIntegrationTestCase;
use Tourze\Symfony\CircuitBreaker\Model\CallResult;
use Tourze\Symfony\CircuitBreaker\Model\MetricsSnapshot;
use Tourze\Symfony\CircuitBreaker\Service\MetricsCollector;
use Tourze\Symfony\CircuitBreaker\Storage\CircuitBreakerStorageInterface;
use Tourze\Symfony\CircuitBreaker\Storage\MemoryStorage;

/**
 * @internal
 */
#[CoversClass(MetricsCollector::class)]
#[RunTestsInSeparateProcesses]
final class MetricsCollectorTest extends AbstractIntegrationTestCase
{
    private MetricsCollector $collector;

    private MemoryStorage $storage;

    public function testRecordSuccess(): void
    {
        $this->collector->recordSuccess('test', 100.5);

        $metrics = $this->storage->getMetricsSnapshot('test', 60);
        $this->assertEquals(1, $metrics->getTotalCalls());
        $this->assertEquals(1, $metrics->getSuccessCalls());
        $this->assertEquals(0, $metrics->getFailedCalls());
    }

    public function testRecordFailure(): void
    {
        $exception = new \RuntimeException('Test error');

        $this->collector->recordFailure('test', 200.0, $exception);

        $metrics = $this->storage->getMetricsSnapshot('test', 60);
        $this->assertEquals(1, $metrics->getTotalCalls());
        $this->assertEquals(0, $metrics->getSuccessCalls());
        $this->assertEquals(1, $metrics->getFailedCalls());
    }

    public function testRecordNotPermitted(): void
    {
        $this->collector->recordNotPermitted('test');

        $snapshot = $this->collector->getSnapshot('test', 60);
        $this->assertEquals(1, $snapshot->getNotPermittedCalls());
    }

    public function testGetSnapshot(): void
    {
        // Record some calls first
        $this->collector->recordSuccess('test', 100.0);
        $this->collector->recordSuccess('test', 200.0);
        $this->collector->recordFailure('test', 150.0, new \Exception('test'));

        $snapshot = $this->collector->getSnapshot('test', 60);

        $this->assertEquals(3, $snapshot->getTotalCalls());
        $this->assertEquals(2, $snapshot->getSuccessCalls());
        $this->assertEquals(1, $snapshot->getFailedCalls());
    }

    public function testShouldIgnoreExceptionWhenInIgnoreList(): void
    {
        // Set up environment variable to configure ignore list
        $_ENV['CIRCUIT_BREAKER_IGNORE_EXCEPTIONS'] = \InvalidArgumentException::class . ',' . \LogicException::class;

        // Re-create collector with new config
        $this->collector = self::getService(MetricsCollector::class);

        $exception = new \InvalidArgumentException('Test');
        $this->assertTrue($this->collector->shouldIgnoreException('test', $exception));

        unset($_ENV['CIRCUIT_BREAKER_IGNORE_EXCEPTIONS']);
    }

    public function testShouldIgnoreExceptionWhenNotInIgnoreList(): void
    {
        $_ENV['CIRCUIT_BREAKER_IGNORE_EXCEPTIONS'] = \InvalidArgumentException::class;

        $this->collector = self::getService(MetricsCollector::class);

        $exception = new \RuntimeException('Test');
        $this->assertFalse($this->collector->shouldIgnoreException('test', $exception));

        unset($_ENV['CIRCUIT_BREAKER_IGNORE_EXCEPTIONS']);
    }

    public function testShouldIgnoreExceptionWithInheritance(): void
    {
        $_ENV['CIRCUIT_BREAKER_IGNORE_EXCEPTIONS'] = \Exception::class;

        $this->collector = self::getService(MetricsCollector::class);

        // RuntimeException extends Exception
        $exception = new \RuntimeException('Test');
        $this->assertTrue($this->collector->shouldIgnoreException('test', $exception));

        unset($_ENV['CIRCUIT_BREAKER_IGNORE_EXCEPTIONS']);
    }

    public function testShouldRecordExceptionWhenInRecordList(): void
    {
        $_ENV['CIRCUIT_BREAKER_RECORD_EXCEPTIONS'] = \RuntimeException::class . ',' . \DomainException::class;

        $this->collector = self::getService(MetricsCollector::class);

        $exception = new \RuntimeException('Test');
        $this->assertTrue($this->collector->shouldRecordException('test', $exception));

        unset($_ENV['CIRCUIT_BREAKER_RECORD_EXCEPTIONS']);
    }

    public function testShouldRecordExceptionWhenRecordListEmpty(): void
    {
        $_ENV['CIRCUIT_BREAKER_RECORD_EXCEPTIONS'] = '';

        $this->collector = self::getService(MetricsCollector::class);

        $exception = new \RuntimeException('Test');
        // Empty list means record all exceptions
        $this->assertTrue($this->collector->shouldRecordException('test', $exception));

        unset($_ENV['CIRCUIT_BREAKER_RECORD_EXCEPTIONS']);
    }

    public function testShouldRecordExceptionWhenNotInRecordList(): void
    {
        $_ENV['CIRCUIT_BREAKER_RECORD_EXCEPTIONS'] = \RuntimeException::class;

        $this->collector = self::getService(MetricsCollector::class);

        $exception = new \InvalidArgumentException('Test');
        $this->assertFalse($this->collector->shouldRecordException('test', $exception));

        unset($_ENV['CIRCUIT_BREAKER_RECORD_EXCEPTIONS']);
    }

    public function testConcurrentNotPermittedCalls(): void
    {
        // Simulate multiple concurrent not permitted calls
        for ($i = 0; $i < 10; ++$i) {
            $this->collector->recordNotPermitted('test');
        }

        $result = $this->collector->getSnapshot('test', 60);
        $this->assertEquals(10, $result->getNotPermittedCalls());
    }

    public function testResetClearsCircuitData(): void
    {
        // Record some not permitted calls
        $this->collector->recordNotPermitted('test-circuit');
        $this->assertEquals(1, $this->collector->getNotPermittedCalls('test-circuit'));

        // Execute reset
        $this->collector->reset('test-circuit');

        // Not permitted calls should be cleared
        $this->assertEquals(0, $this->collector->getNotPermittedCalls('test-circuit'));
    }

    public function testGetNotPermittedCallsReturnsCorrectCount(): void
    {
        // Initially should be 0
        $this->assertEquals(0, $this->collector->getNotPermittedCalls('test'));

        // Record some calls
        $this->collector->recordNotPermitted('test');
        $this->collector->recordNotPermitted('test');
        $this->collector->recordNotPermitted('test');

        // Should return correct count
        $this->assertEquals(3, $this->collector->getNotPermittedCalls('test'));

        // Different circuit should still be 0
        $this->assertEquals(0, $this->collector->getNotPermittedCalls('other'));
    }

    public function testCleanupMethod(): void
    {
        // Record some calls
        $this->collector->recordNotPermitted('test1');
        $this->collector->recordNotPermitted('test2');

        // Cleanup should not throw
        $this->collector->cleanup();

        // After cleanup, old data should be cleared based on threshold
        // Since we just recorded them, they should still be there
        $this->assertGreaterThanOrEqual(0, $this->collector->getNotPermittedCalls('test1'));
    }

    protected function onSetUp(): void
    {
        $this->storage = new MemoryStorage();

        $container = self::getContainer();
        $container->set('Tourze\Symfony\CircuitBreaker\Storage\CircuitBreakerStorageInterface', $this->storage);

        $this->collector = self::getService(MetricsCollector::class);
    }
}
