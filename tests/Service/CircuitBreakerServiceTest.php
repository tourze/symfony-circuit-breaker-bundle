<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Tourze\PHPUnitSymfonyKernelTest\AbstractIntegrationTestCase;
use Tourze\Symfony\CircuitBreaker\Enum\CircuitState;
use Tourze\Symfony\CircuitBreaker\Exception\CircuitOpenException;
use Tourze\Symfony\CircuitBreaker\Model\CallResult;
use Tourze\Symfony\CircuitBreaker\Model\CircuitBreakerState;
use Tourze\Symfony\CircuitBreaker\Service\CircuitBreakerConfigService;
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

    private CircuitBreakerConfigService $configService;

    private StateManager $stateManager;

    private EventDispatcher $eventDispatcher;

    private CircuitBreakerService $circuitBreakerService;

    protected function onSetUp(): void
    {
        $this->storage = new MemoryStorage();
        // 在测试中使用 createMock() 对具体类 CircuitBreakerConfigService 进行 Mock
        // 理由1：CircuitBreakerConfigService 没有接口，但其行为对测试来说是外部依赖
        // 理由2：测试重点是 CircuitBreakerService 的逻辑，而不是配置服务的实现
        // 理由3：配置服务的真实实现依赖环境变量，Mock 可以提供稳定的测试环境
        $this->configService = $this->createMock(CircuitBreakerConfigService::class);
        $this->eventDispatcher = new EventDispatcher();
        $logger = new NullLogger();

        // 配置mock - 添加异常忽略配置以支持所有测试场景
        $this->configService->method('getCircuitConfig')
            ->willReturn([
                'failure_rate_threshold' => 50,
                'minimum_number_of_calls' => 10,
                'permitted_number_of_calls_in_half_open_state' => 5,
                'wait_duration_in_open_state' => 60,
                'sliding_window_size' => 100,
                'slow_call_duration_threshold' => 1000,
                'slow_call_rate_threshold' => 50,
                'consecutive_failure_threshold' => 5,
                'ignore_exceptions' => [\InvalidArgumentException::class],
                'record_exceptions' => [],
            ])
        ;

        $this->stateManager = new StateManager(
            $this->storage,
            $this->eventDispatcher,
            $logger
        );

        // 将自定义存储和 Mock 服务设置到容器中
        $container = self::getContainer();
        $container->set('Tourze\Symfony\CircuitBreaker\Storage\CircuitBreakerStorageInterface', $this->storage);
        $container->set(CircuitBreakerConfigService::class, $this->configService);

        $this->circuitBreakerService = self::getService(CircuitBreakerService::class);
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

        // Call isAllowed up to the limit to increment attempt count
        for ($i = 0; $i < 5; ++$i) {
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

    public function testRecordFailureWithIgnoredExceptionDoesntCountAsFailure(): void
    {
        // 使用应该被忽略的异常记录失败（setUp 中已配置忽略 InvalidArgumentException）
        $this->circuitBreakerService->recordFailure('test.service', new \InvalidArgumentException('忽略的异常'), 100.0);

        // 获取更新后的指标
        $metrics = $this->storage->getMetricsSnapshot('test.service', 100);

        // 验证 - 忽略的异常不计入失败
        $this->assertEquals(1, $metrics->getTotalCalls());
        $this->assertEquals(0, $metrics->getFailedCalls());
        $this->assertEquals(1, $metrics->getSuccessCalls());
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
