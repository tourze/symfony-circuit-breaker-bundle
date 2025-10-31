<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tourze\PHPUnitSymfonyKernelTest\AbstractIntegrationTestCase;
use Tourze\Symfony\CircuitBreaker\Enum\CircuitState;
use Tourze\Symfony\CircuitBreaker\Model\CircuitBreakerState;
use Tourze\Symfony\CircuitBreaker\Service\CircuitBreakerRegistry;
use Tourze\Symfony\CircuitBreaker\Storage\MemoryStorage;

/**
 * @internal
 */
#[CoversClass(CircuitBreakerRegistry::class)]
#[RunTestsInSeparateProcesses]
final class CircuitBreakerRegistryTest extends AbstractIntegrationTestCase
{
    private CircuitBreakerRegistry $registry;

    private MemoryStorage $storage;

    protected function onSetUp(): void
    {
        $this->storage = new MemoryStorage();

        // 将自定义存储设置到容器中，确保 Registry 使用相同的存储实例
        $container = self::getContainer();
        $container->set('Tourze\Symfony\CircuitBreaker\Storage\CircuitBreakerStorageInterface', $this->storage);

        $this->registry = self::getService(CircuitBreakerRegistry::class);
    }

    public function testGetCircuitInfoReturnsArray(): void
    {
        $info = $this->registry->getCircuitInfo('test-circuit');

        $this->assertArrayHasKey('name', $info);
        $this->assertEquals('test-circuit', $info['name']);
    }

    public function testGetAllCircuitsReturnsEmptyArrayInitially(): void
    {
        $circuits = $this->registry->getAllCircuits();

        $this->assertEmpty($circuits);
    }

    public function testClearCacheRemovesCachedInformation(): void
    {
        // 首先获取熔断器信息，这会填充缓存
        $info1 = $this->registry->getCircuitInfo('test-circuit');

        // 清除缓存
        $this->registry->clearCache();

        // 再次获取信息（此时需要重新计算）
        $info2 = $this->registry->getCircuitInfo('test-circuit');

        // 两次获取的信息应该是相同的，这证明清除缓存后能正常重新计算
        $this->assertEquals($info1['name'], $info2['name']);
        $this->assertEquals($info1['state'], $info2['state']);
    }

    public function testGetAllCircuitsInfoReturnsCompleteInformation(): void
    {
        // 手动添加一个熔断器状态到存储
        $state = new CircuitBreakerState(
            CircuitState::CLOSED
        );
        $this->storage->saveState('test-circuit', $state);

        $allInfo = $this->registry->getAllCircuitsInfo();

        $this->assertArrayHasKey('test-circuit', $allInfo);
        $this->assertArrayHasKey('name', $allInfo['test-circuit']);
        $this->assertArrayHasKey('state', $allInfo['test-circuit']);
        $this->assertArrayHasKey('metrics', $allInfo['test-circuit']);
        $this->assertArrayHasKey('config', $allInfo['test-circuit']);
    }

    public function testGetHealthStatusReturnsCorrectSummary(): void
    {
        // 添加几个不同状态的熔断器
        $closedState = new CircuitBreakerState(
            CircuitState::CLOSED
        );
        $openState = new CircuitBreakerState(
            CircuitState::OPEN
        );
        $halfOpenState = new CircuitBreakerState(
            CircuitState::HALF_OPEN
        );

        $this->storage->saveState('circuit-closed', $closedState);
        $this->storage->saveState('circuit-open', $openState);
        $this->storage->saveState('circuit-half-open', $halfOpenState);

        $healthStatus = $this->registry->getHealthStatus();

        $this->assertEquals(3, $healthStatus['total_circuits']);
        $this->assertEquals(1, $healthStatus['closed_circuits']);
        $this->assertEquals(1, $healthStatus['open_circuits']);
        $this->assertEquals(1, $healthStatus['half_open_circuits']);
        $this->assertArrayHasKey('storage_type', $healthStatus);
        $this->assertArrayHasKey('storage_available', $healthStatus);
    }
}
