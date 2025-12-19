<?php

namespace Tourze\Symfony\CircuitBreaker\Service;

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Tourze\Symfony\CircuitBreaker\Storage\ChainedStorage;
use Tourze\Symfony\CircuitBreaker\Storage\CircuitBreakerStorageInterface;
use Tourze\Symfony\CircuitBreaker\Storage\DoctrineStorage;
use Tourze\Symfony\CircuitBreaker\Storage\MemoryStorage;
use Tourze\Symfony\CircuitBreaker\Storage\RedisAtomicStorage;

/**
 * 熔断器注册中心
 *
 * 管理所有熔断器实例的中央注册表
 */
#[Autoconfigure]
final class CircuitBreakerRegistry
{
    private const CACHE_TTL = 1;

    /**
     * @var array<string, array<string, mixed>> 熔断器信息缓存
     */
    private array $circuitInfoCache = [];

    /**
     * @var array<string, int> 缓存时间戳
     */
    private array $cacheTimestamps = []; // 1秒缓存

    public function __construct(
        private readonly CircuitBreakerStorageInterface $storage,
        private readonly CircuitBreakerConfigService $configService,
        private readonly StateManager $stateManager,
        private readonly MetricsCollector $metricsCollector,
    ) {
    }

    /**
     * 获取所有熔断器的汇总信息
     *
     * @return array<string, array<string, mixed>>
     */
    public function getAllCircuitsInfo(): array
    {
        $circuits = [];
        foreach ($this->getAllCircuits() as $name) {
            $circuits[$name] = $this->getCircuitInfo($name);
        }

        return $circuits;
    }

    /**
     * 获取所有熔断器名称
     *
     * @return array<string>
     */
    public function getAllCircuits(): array
    {
        return $this->storage->getAllCircuitNames();
    }

    /**
     * 获取熔断器详细信息
     *
     * @return array<string, mixed>
     */
    public function getCircuitInfo(string $name): array
    {
        // 检查缓存
        if (isset($this->circuitInfoCache[$name])
            && time() - ($this->cacheTimestamps[$name] ?? 0) < self::CACHE_TTL) {
            return $this->circuitInfoCache[$name];
        }

        $state = $this->stateManager->getState($name);
        $config = $this->configService->getCircuitConfig($name);
        $metrics = $this->metricsCollector->getSnapshot($name, $config['sliding_window_size']);

        $info = [
            'name' => $name,
            'state' => $state->getState()->value,
            'state_timestamp' => $state->getTimestamp(),
            'metrics' => $metrics->toArray(),
            'config' => $config,
            'storage' => $this->getStorageType(),
        ];

        // 缓存结果
        $this->circuitInfoCache[$name] = $info;
        $this->cacheTimestamps[$name] = time();

        return $info;
    }

    /**
     * 获取当前存储类型
     */
    private function getStorageType(): string
    {
        if ($this->storage instanceof ChainedStorage) {
            return $this->storage->getActiveStorageType();
        }

        return match (true) {
            $this->storage instanceof RedisAtomicStorage => 'redis',
            $this->storage instanceof DoctrineStorage => 'doctrine',
            $this->storage instanceof MemoryStorage => 'memory',
            default => 'unknown',
        };
    }

    /**
     * 获取熔断器健康状态
     *
     * @return array<string, mixed>
     */
    public function getHealthStatus(): array
    {
        $circuits = $this->getAllCircuits();
        $totalCircuits = count($circuits);
        $openCircuits = 0;
        $halfOpenCircuits = 0;
        $closedCircuits = 0;

        foreach ($circuits as $name) {
            $state = $this->stateManager->getState($name);
            switch ($state->getState()->value) {
                case 'open':
                    $openCircuits++;
                    break;
                case 'half_open':
                    $halfOpenCircuits++;
                    break;
                case 'closed':
                    $closedCircuits++;
                    break;
            }
        }

        return [
            'total_circuits' => $totalCircuits,
            'open_circuits' => $openCircuits,
            'half_open_circuits' => $halfOpenCircuits,
            'closed_circuits' => $closedCircuits,
            'storage_type' => $this->getStorageType(),
            'storage_available' => $this->storage->isAvailable(),
        ];
    }

    /**
     * 清除缓存
     */
    public function clearCache(): void
    {
        $this->circuitInfoCache = [];
        $this->cacheTimestamps = [];
    }
}
