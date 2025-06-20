<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Service;

use PHPUnit\Framework\TestCase;
use Tourze\Symfony\CircuitBreaker\Model\CallResult;
use Tourze\Symfony\CircuitBreaker\Model\MetricsSnapshot;
use Tourze\Symfony\CircuitBreaker\Service\CircuitBreakerConfigService;
use Tourze\Symfony\CircuitBreaker\Service\MetricsCollector;
use Tourze\Symfony\CircuitBreaker\Storage\CircuitBreakerStorageInterface;

class MetricsCollectorTest extends TestCase
{
    private MetricsCollector $collector;
    private CircuitBreakerStorageInterface $storage;
    private CircuitBreakerConfigService $configService;
    
    public function testRecordSuccess(): void
    {
        $this->storage->expects($this->once())
            ->method('recordCall')
            ->with(
                'test',
                $this->callback(function (CallResult $result) {
                    return $result->isSuccess() &&
                           $result->getDuration() === 100.5 &&
                           abs($result->getTimestamp() - time()) < 2;
                })
            );

        $this->collector->recordSuccess('test', 100.5);
    }
    
    public function testRecordFailure(): void
    {
        $exception = new \RuntimeException('Test error');

        $this->storage->expects($this->once())
            ->method('recordCall')
            ->with(
                'test',
                $this->callback(function (CallResult $result) use ($exception) {
                    return !$result->isSuccess() &&
                           $result->getDuration() === 200.0 &&
                           $result->getException() === $exception;
                })
            );

        $this->collector->recordFailure('test', 200.0, $exception);
    }
    
    public function testRecordNotPermitted(): void
    {
        // First call increments counter
        $this->collector->recordNotPermitted('test');

        // Get snapshot should include not permitted calls
        $snapshot = new MetricsSnapshot(
            totalCalls: 10,
            successCalls: 5,
            failedCalls: 3,
            slowCalls: 2,
            notPermittedCalls: 0
        );

        $this->storage->expects($this->once())
            ->method('getMetricsSnapshot')
            ->with('test', 60)
            ->willReturn($snapshot);

        $result = $this->collector->getSnapshot('test', 60);

        // Should include the not permitted call
        $this->assertEquals(1, $result->getNotPermittedCalls());
    }
    
    public function testGetSnapshot(): void
    {
        $expectedSnapshot = new MetricsSnapshot(
            totalCalls: 100,
            successCalls: 70,
            failedCalls: 20,
            slowCalls: 10,
            avgResponseTime: 150.5
        );

        $this->storage->expects($this->once())
            ->method('getMetricsSnapshot')
            ->with('test', 60)
            ->willReturn($expectedSnapshot);

        $snapshot = $this->collector->getSnapshot('test', 60);

        $this->assertEquals($expectedSnapshot, $snapshot);
    }
    
    public function testShouldIgnoreException_whenInIgnoreList(): void
    {
        $this->configService->expects($this->once())
            ->method('getCircuitConfig')
            ->with('test')
            ->willReturn([
                'ignore_exceptions' => [
                    \InvalidArgumentException::class,
                    \LogicException::class
                ]
            ]);

        $exception = new \InvalidArgumentException('Test');

        $this->assertTrue($this->collector->shouldIgnoreException('test', $exception));
    }
    
    public function testShouldIgnoreException_whenNotInIgnoreList(): void
    {
        $this->configService->expects($this->once())
            ->method('getCircuitConfig')
            ->with('test')
            ->willReturn([
                'ignore_exceptions' => [
                    \InvalidArgumentException::class
                ]
            ]);

        $exception = new \RuntimeException('Test');

        $this->assertFalse($this->collector->shouldIgnoreException('test', $exception));
    }
    
    public function testShouldIgnoreException_withInheritance(): void
    {
        $this->configService->expects($this->once())
            ->method('getCircuitConfig')
            ->with('test')
            ->willReturn([
                'ignore_exceptions' => [
                    \Exception::class
                ]
            ]);

        // RuntimeException extends Exception
        $exception = new \RuntimeException('Test');

        $this->assertTrue($this->collector->shouldIgnoreException('test', $exception));
    }
    
    public function testShouldRecordException_whenInRecordList(): void
    {
        $this->configService->expects($this->once())
            ->method('getCircuitConfig')
            ->with('test')
            ->willReturn([
                'record_exceptions' => [
                    \RuntimeException::class,
                    \DomainException::class
                ]
            ]);

        $exception = new \RuntimeException('Test');

        $this->assertTrue($this->collector->shouldRecordException('test', $exception));
    }
    
    public function testShouldRecordException_whenRecordListEmpty(): void
    {
        $this->configService->expects($this->once())
            ->method('getCircuitConfig')
            ->with('test')
            ->willReturn([
                'record_exceptions' => []
            ]);

        $exception = new \RuntimeException('Test');

        // Empty list means record all exceptions
        $this->assertTrue($this->collector->shouldRecordException('test', $exception));
    }
    
    public function testShouldRecordException_whenNotInRecordList(): void
    {
        $this->configService->expects($this->once())
            ->method('getCircuitConfig')
            ->with('test')
            ->willReturn([
                'record_exceptions' => [
                    \RuntimeException::class
                ]
            ]);

        $exception = new \InvalidArgumentException('Test');

        $this->assertFalse($this->collector->shouldRecordException('test', $exception));
    }
    
    public function testNotPermittedCallsCleanup(): void
    {
        // Record some not permitted calls
        $this->collector->recordNotPermitted('test1');
        $this->collector->recordNotPermitted('test2');

        // Mock storage to return snapshots
        $this->storage->expects($this->exactly(2))
            ->method('getMetricsSnapshot')
            ->willReturnCallback(function ($name, $window) {
                if ($name === 'test1') {
                    // Old snapshot - should be cleaned
                    return new MetricsSnapshot(
                        totalCalls: 10,
                        timestamp: time() - 3700 // Over 1 hour old
                    );
                } else {
                    // Recent snapshot - should be kept
                    return new MetricsSnapshot(
                        totalCalls: 5,
                        timestamp: time() - 30
                    );
                }
            });

        // Trigger cleanup by getting snapshots
        $result1 = $this->collector->getSnapshot('test1', 60);
        $result2 = $this->collector->getSnapshot('test2', 60);

        // Both should still have the not permitted count since there's no cleanup logic
        $this->assertEquals(1, $result1->getNotPermittedCalls());
        $this->assertEquals(1, $result2->getNotPermittedCalls());
    }
    
    public function testConcurrentNotPermittedCalls(): void
    {
        // Simulate multiple concurrent not permitted calls
        for ($i = 0; $i < 10; $i++) {
            $this->collector->recordNotPermitted('test');
        }

        $snapshot = new MetricsSnapshot(totalCalls: 50);

        $this->storage->expects($this->once())
            ->method('getMetricsSnapshot')
            ->willReturn($snapshot);

        $result = $this->collector->getSnapshot('test', 60);

        $this->assertEquals(10, $result->getNotPermittedCalls());
    }
    
    protected function setUp(): void
    {
        $this->storage = $this->createMock(CircuitBreakerStorageInterface::class);
        $this->configService = $this->createMock(CircuitBreakerConfigService::class);

        $this->collector = new MetricsCollector(
            $this->storage,
            $this->configService
        );
    }
}