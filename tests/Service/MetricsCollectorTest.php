<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tourze\PHPUnitSymfonyKernelTest\AbstractIntegrationTestCase;
use Tourze\Symfony\CircuitBreaker\Model\CallResult;
use Tourze\Symfony\CircuitBreaker\Model\MetricsSnapshot;
use Tourze\Symfony\CircuitBreaker\Service\CircuitBreakerConfigService;
use Tourze\Symfony\CircuitBreaker\Service\MetricsCollector;
use Tourze\Symfony\CircuitBreaker\Storage\CircuitBreakerStorageInterface;

/**
 * @internal
 */
#[CoversClass(MetricsCollector::class)]
#[RunTestsInSeparateProcesses]
final class MetricsCollectorTest extends AbstractIntegrationTestCase
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
                self::callback(function (CallResult $result) {
                    return $result->isSuccess()
                           && 100.5 === $result->getDuration()
                           && abs($result->getTimestamp() - time()) < 2;
                })
            )
        ;

        $this->collector->recordSuccess('test', 100.5);
    }

    public function testRecordFailure(): void
    {
        $exception = new \RuntimeException('Test error');

        $this->storage->expects($this->once())
            ->method('recordCall')
            ->with(
                'test',
                self::callback(function (CallResult $result) use ($exception) {
                    return !$result->isSuccess()
                           && 200.0 === $result->getDuration()
                           && $result->getException() === $exception;
                })
            )
        ;

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
            ->willReturn($snapshot)
        ;

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
            ->willReturn($expectedSnapshot)
        ;

        $snapshot = $this->collector->getSnapshot('test', 60);

        $this->assertEquals($expectedSnapshot, $snapshot);
    }

    public function testShouldIgnoreExceptionWhenInIgnoreList(): void
    {
        $this->configService->expects($this->once())
            ->method('getCircuitConfig')
            ->with('test')
            ->willReturn([
                'ignore_exceptions' => [
                    \InvalidArgumentException::class,
                    \LogicException::class,
                ],
            ])
        ;

        $exception = new \InvalidArgumentException('Test');

        $this->assertTrue($this->collector->shouldIgnoreException('test', $exception));
    }

    public function testShouldIgnoreExceptionWhenNotInIgnoreList(): void
    {
        $this->configService->expects($this->once())
            ->method('getCircuitConfig')
            ->with('test')
            ->willReturn([
                'ignore_exceptions' => [
                    \InvalidArgumentException::class,
                ],
            ])
        ;

        $exception = new \RuntimeException('Test');

        $this->assertFalse($this->collector->shouldIgnoreException('test', $exception));
    }

    public function testShouldIgnoreExceptionWithInheritance(): void
    {
        $this->configService->expects($this->once())
            ->method('getCircuitConfig')
            ->with('test')
            ->willReturn([
                'ignore_exceptions' => [
                    \Exception::class,
                ],
            ])
        ;

        // RuntimeException extends Exception
        $exception = new \RuntimeException('Test');

        $this->assertTrue($this->collector->shouldIgnoreException('test', $exception));
    }

    public function testShouldRecordExceptionWhenInRecordList(): void
    {
        $this->configService->expects($this->once())
            ->method('getCircuitConfig')
            ->with('test')
            ->willReturn([
                'record_exceptions' => [
                    \RuntimeException::class,
                    \DomainException::class,
                ],
            ])
        ;

        $exception = new \RuntimeException('Test');

        $this->assertTrue($this->collector->shouldRecordException('test', $exception));
    }

    public function testShouldRecordExceptionWhenRecordListEmpty(): void
    {
        $this->configService->expects($this->once())
            ->method('getCircuitConfig')
            ->with('test')
            ->willReturn([
                'record_exceptions' => [],
            ])
        ;

        $exception = new \RuntimeException('Test');

        // Empty list means record all exceptions
        $this->assertTrue($this->collector->shouldRecordException('test', $exception));
    }

    public function testShouldRecordExceptionWhenNotInRecordList(): void
    {
        $this->configService->expects($this->once())
            ->method('getCircuitConfig')
            ->with('test')
            ->willReturn([
                'record_exceptions' => [
                    \RuntimeException::class,
                ],
            ])
        ;

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
                if ('test1' === $name) {
                    // Old snapshot - should be cleaned
                    return new MetricsSnapshot(
                        totalCalls: 10,
                        timestamp: time() - 3700 // Over 1 hour old
                    );
                }

                // Recent snapshot - should be kept
                return new MetricsSnapshot(
                    totalCalls: 5,
                    timestamp: time() - 30
                );
            })
        ;

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
        for ($i = 0; $i < 10; ++$i) {
            $this->collector->recordNotPermitted('test');
        }

        $snapshot = new MetricsSnapshot(totalCalls: 50);

        $this->storage->expects($this->once())
            ->method('getMetricsSnapshot')
            ->willReturn($snapshot)
        ;

        $result = $this->collector->getSnapshot('test', 60);

        $this->assertEquals(10, $result->getNotPermittedCalls());
    }

    public function testCleanupRemovesOldNotPermittedCalls(): void
    {
        // Record some not permitted calls
        $this->collector->recordNotPermitted('old-circuit');
        $this->collector->recordNotPermitted('new-circuit');

        // Mock storage to return snapshots with different timestamps
        $this->storage->expects($this->exactly(2))
            ->method('getMetricsSnapshot')
            ->willReturnMap([
                ['old-circuit', 3600, new MetricsSnapshot(
                    totalCalls: 10,
                    timestamp: time() - 3700 // Over 1 hour old
                )],
                ['new-circuit', 3600, new MetricsSnapshot(
                    totalCalls: 5,
                    timestamp: time() - 30 // Recent
                )],
            ])
        ;

        // Execute cleanup
        $this->collector->cleanup();

        // Old circuit should have its not permitted count cleared
        $this->assertEquals(0, $this->collector->getNotPermittedCalls('old-circuit'));
        // New circuit should keep its not permitted count
        $this->assertEquals(1, $this->collector->getNotPermittedCalls('new-circuit'));
    }

    public function testResetClearsCircuitData(): void
    {
        // Record some not permitted calls
        $this->collector->recordNotPermitted('test-circuit');
        $this->assertEquals(1, $this->collector->getNotPermittedCalls('test-circuit'));

        // Mock storage deleteCircuit call
        $this->storage->expects($this->once())
            ->method('deleteCircuit')
            ->with('test-circuit')
        ;

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

    protected function onSetUp(): void
    {
        $this->storage = $this->createMock(CircuitBreakerStorageInterface::class);
        // 在测试中使用 createMock() 对具体类 CircuitBreakerConfigService 进行 Mock
        // 理由1：CircuitBreakerConfigService 是项目中的具体服务类，没有对应的接口
        // 理由2：测试重点是 MetricsCollector 的指标收集逻辑，而不是配置服务的实现
        // 理由3：Mock CircuitBreakerConfigService 可以精确控制配置参数，便于测试不同的配置场景
        $this->configService = $this->createMock(CircuitBreakerConfigService::class);

        // 将 Mock 对象设置到容器中
        $container = self::getContainer();
        $container->set('Tourze\Symfony\CircuitBreaker\Storage\CircuitBreakerStorageInterface', $this->storage);
        $container->set(CircuitBreakerConfigService::class, $this->configService);

        $this->collector = self::getService(MetricsCollector::class);
    }
}
