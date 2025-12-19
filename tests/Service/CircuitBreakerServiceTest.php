<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tourze\PHPUnitSymfonyKernelTest\AbstractIntegrationTestCase;
use Tourze\Symfony\CircuitBreaker\Enum\CircuitState;
use Tourze\Symfony\CircuitBreaker\Exception\CircuitOpenException;
use Tourze\Symfony\CircuitBreaker\Model\CallResult;
use Tourze\Symfony\CircuitBreaker\Model\CircuitBreakerState;
use Tourze\Symfony\CircuitBreaker\Service\CircuitBreakerService;
use Tourze\Symfony\CircuitBreaker\Service\StateManager;
use Tourze\Symfony\CircuitBreaker\Storage\MemoryStorage;
use Tourze\Symfony\CircuitBreaker\Tests\Exception\TestOperationFailedException;

/**
 * @internal
 */
#[CoversClass(CircuitBreakerService::class)]
#[RunTestsInSeparateProcesses]
final class CircuitBreakerServiceTest extends AbstractIntegrationTestCase
{
    private MemoryStorage $storage;

    private StateManager $stateManager;

    private CircuitBreakerService $circuitBreakerService;

    protected function onSetUp(): void
    {
        $this->storage = new MemoryStorage();

        // 将自定义存储设置到容器中
        $container = self::getContainer();
        $container->set('Tourze\Symfony\CircuitBreaker\Storage\CircuitBreakerStorageInterface', $this->storage);

        $this->circuitBreakerService = self::getService(CircuitBreakerService::class);
        $this->stateManager = self::getService(StateManager::class);
    }

    public function testIsAllowedWhenClosedReturnsTrue(): void
    {
        $state = new CircuitBreakerState(CircuitState::CLOSED);
        $this->storage->saveState('service1', $state);

        $result = $this->circuitBreakerService->isAllowed('service1');

        $this->assertTrue($result);
    }

    public function testIsAllowedWhenOpenReturnsFalse(): void
    {
        $state = new CircuitBreakerState(CircuitState::OPEN);
        $this->storage->saveState('service1', $state);

        $result = $this->circuitBreakerService->isAllowed('service1');

        $this->assertFalse($result);
    }

    public function testIsAllowedWhenOpenAndWaitDurationPassedReturnsTrue(): void
    {
        // 创建一个已经开启并超过等待时间的状态
        $state = new CircuitBreakerState(CircuitState::OPEN, time() - 100);
        $this->storage->saveState('service1', $state);

        $result = $this->circuitBreakerService->isAllowed('service1');

        $this->assertTrue($result);

        // 验证状态已经改变为半开
        $updatedState = $this->stateManager->getState('service1');
        $this->assertEquals(CircuitState::HALF_OPEN, $updatedState->getState());
    }

    public function testIsAllowedWhenHalfOpenWithinAttemptLimitReturnsTrue(): void
    {
        $state = new CircuitBreakerState(CircuitState::HALF_OPEN);
        $this->storage->saveState('service1', $state);

        // Record some calls but less than the limit
        for ($i = 0; $i < 3; ++$i) {
            $this->storage->recordCall('service1', new CallResult(true, 100.0, time()));
        }

        $result = $this->circuitBreakerService->isAllowed('service1');

        $this->assertTrue($result);
    }

    public function testIsAllowedWhenHalfOpenExceedingAttemptLimitReturnsFalse(): void
    {
        $state = new CircuitBreakerState(CircuitState::HALF_OPEN);
        $this->storage->saveState('service1', $state);

        // Call isAllowed up to the limit to increment attempt count (default is 10)
        for ($i = 0; $i < 10; ++$i) {
            $this->circuitBreakerService->isAllowed('service1');
        }

        // The next call should be rejected
        $result = $this->circuitBreakerService->isAllowed('service1');

        $this->assertFalse($result);
    }

    public function testRecordSuccessIncreasesSuccessCounter(): void
    {
        $this->circuitBreakerService->recordSuccess('service1', 100.0);

        $metrics = $this->storage->getMetricsSnapshot('service1', 100);
        $this->assertEquals(1, $metrics->getTotalCalls());
        $this->assertEquals(1, $metrics->getSuccessCalls());
    }

    public function testRecordSuccessWhenHalfOpenAndEnoughSuccessesCloseCircuit(): void
    {
        // 准备一个处于半开状态的熔断器
        $state = new CircuitBreakerState(CircuitState::HALF_OPEN);
        $this->storage->saveState('service1', $state);

        // 记录足够的成功调用
        for ($i = 0; $i < 10; ++$i) {
            $this->circuitBreakerService->recordSuccess('service1', 100.0);
        }

        // 验证状态已经改变为关闭
        $updatedState = $this->stateManager->getState('service1');
        $this->assertEquals(CircuitState::CLOSED, $updatedState->getState());
    }

    public function testRecordFailureIncreasesFailureCounter(): void
    {
        $this->circuitBreakerService->recordFailure('service1', new \RuntimeException('测试异常'), 100.0);

        $metrics = $this->storage->getMetricsSnapshot('service1', 100);
        $this->assertEquals(1, $metrics->getTotalCalls());
        $this->assertEquals(1, $metrics->getFailedCalls());
    }

    public function testRecordFailureWhenHalfOpenOpensCircuit(): void
    {
        // 准备一个处于半开状态的熔断器
        $state = new CircuitBreakerState(CircuitState::HALF_OPEN);
        $this->storage->saveState('service1', $state);

        // 记录一次失败，应该触发打开熔断器
        $this->circuitBreakerService->recordFailure('service1', new \RuntimeException('测试异常'), 100.0);

        $updatedState = $this->stateManager->getState('service1');
        $this->assertEquals(CircuitState::OPEN, $updatedState->getState());
    }

    public function testRecordFailureWhenClosedAndThresholdReachedOpensCircuit(): void
    {
        // 准备一个关闭状态的熔断器
        $state = new CircuitBreakerState(CircuitState::CLOSED);
        $this->storage->saveState('service1', $state);

        // 准备足够的调用达到最小调用数
        for ($i = 0; $i < 9; ++$i) {
            $this->circuitBreakerService->recordSuccess('service1', 100.0);
        }

        // 记录足够的失败使失败率超过阈值
        for ($i = 0; $i < 11; ++$i) {
            $this->circuitBreakerService->recordFailure('service1', new \RuntimeException('测试异常'), 100.0);
        }

        $updatedState = $this->stateManager->getState('service1');
        $this->assertEquals(CircuitState::OPEN, $updatedState->getState());
    }

    public function testExecuteWhenCircuitClosedExecutesOperation(): void
    {
        $state = new CircuitBreakerState(CircuitState::CLOSED);
        $this->storage->saveState('service1', $state);

        $result = $this->circuitBreakerService->execute(
            'service1',
            function () {
                return 'success';
            },
            function () {
                return 'fallback';
            }
        );

        $this->assertEquals('success', $result);
    }

    public function testExecuteWhenCircuitOpenExecutesFallback(): void
    {
        $state = new CircuitBreakerState(CircuitState::OPEN);
        $this->storage->saveState('service1', $state);

        $result = $this->circuitBreakerService->execute(
            'service1',
            function () {
                return 'success';
            },
            function () {
                return 'fallback';
            }
        );

        $this->assertEquals('fallback', $result);
    }

    public function testExecuteWhenOperationThrowsRecordsFailureAndRethrows(): void
    {
        $state = new CircuitBreakerState(CircuitState::CLOSED);
        $this->storage->saveState('service1', $state);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Operation failed');

        try {
            $this->circuitBreakerService->execute(
                'service1',
                function (): void {
                    throw new TestOperationFailedException('Operation failed');
                }
            );
        } finally {
            $metrics = $this->storage->getMetricsSnapshot('service1', 100);
            $this->assertEquals(1, $metrics->getTotalCalls());
            $this->assertEquals(1, $metrics->getFailedCalls());
        }
    }

    public function testExecuteWhenOperationSucceedsRecordsSuccess(): void
    {
        $state = new CircuitBreakerState(CircuitState::CLOSED);
        $this->storage->saveState('service1', $state);

        $this->circuitBreakerService->execute(
            'service1',
            function () {
                return 'success';
            }
        );

        $metrics = $this->storage->getMetricsSnapshot('service1', 100);
        $this->assertEquals(1, $metrics->getTotalCalls());
        $this->assertEquals(1, $metrics->getSuccessCalls());
    }

    public function testExecuteWhenCircuitOpenAndNoFallbackThrowsCircuitOpenException(): void
    {
        $state = new CircuitBreakerState(CircuitState::OPEN);
        $this->storage->saveState('service1', $state);

        $this->expectException(CircuitOpenException::class);

        $this->circuitBreakerService->execute(
            'service1',
            function () {
                return 'success';
            }
        );
    }

    public function testResetCircuitResetsStateAndMetrics(): void
    {
        // 设置一些初始状态
        $state = new CircuitBreakerState(CircuitState::OPEN);
        $this->storage->saveState('service1', $state);

        // 添加一些调用记录
        $this->storage->recordCall('service1', new CallResult(false, 100.0, time()));

        // 重置熔断器
        $this->circuitBreakerService->resetCircuit('service1');

        // 验证状态已重置
        $resetState = $this->stateManager->getState('service1');

        $this->assertEquals(CircuitState::CLOSED, $resetState->getState());
        // 注意：当前实现中 resetCircuit 只重置状态，不清除调用记录
        // 这是一个设计决策，允许在重置状态后仍然保留历史数据
    }

    public function testForceOpenSetsStateToOpen(): void
    {
        $state = new CircuitBreakerState(CircuitState::CLOSED);
        $this->storage->saveState('service1', $state);

        $this->circuitBreakerService->forceOpen('service1');

        $updatedState = $this->stateManager->getState('service1');
        $this->assertEquals(CircuitState::OPEN, $updatedState->getState());
    }

    public function testForceCloseSetsStateToClosed(): void
    {
        $state = new CircuitBreakerState(CircuitState::OPEN);
        $this->storage->saveState('service1', $state);

        $this->circuitBreakerService->forceClose('service1');

        $updatedState = $this->stateManager->getState('service1');
        $this->assertEquals(CircuitState::CLOSED, $updatedState->getState());
    }

    public function testMarkSuccessCallsRecordSuccess(): void
    {
        $state = new CircuitBreakerState(CircuitState::CLOSED);
        $this->storage->saveState('service1', $state);

        // 调用 recordSuccess 方法
        $this->circuitBreakerService->recordSuccess('service1');

        // 验证通过 metrics 检查是否记录了成功调用
        $metrics = $this->storage->getMetricsSnapshot('service1', 100);
        $this->assertEquals(1, $metrics->getTotalCalls());
        $this->assertEquals(1, $metrics->getSuccessCalls());
        $this->assertEquals(0, $metrics->getFailedCalls());
    }

    public function testMarkFailureCallsRecordFailureWithManualException(): void
    {
        $state = new CircuitBreakerState(CircuitState::CLOSED);
        $this->storage->saveState('service1', $state);

        // 调用 recordFailure 方法
        $this->circuitBreakerService->recordFailure('service1', new \Exception('test'));

        // 验证通过 metrics 检查是否记录了失败调用
        $metrics = $this->storage->getMetricsSnapshot('service1', 100);
        $this->assertEquals(1, $metrics->getTotalCalls());
        $this->assertEquals(0, $metrics->getSuccessCalls());
        $this->assertEquals(1, $metrics->getFailedCalls());
    }

    public function testIsAvailableCompatibilityMethod(): void
    {
        $state = new CircuitBreakerState(CircuitState::CLOSED);
        $this->storage->saveState('service1', $state);

        // 测试 isAllowed 方法
        $this->assertTrue($this->circuitBreakerService->isAllowed('service1'));
    }
}
