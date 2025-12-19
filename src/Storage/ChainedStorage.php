<?php

namespace Tourze\Symfony\CircuitBreaker\Storage;

use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Tourze\Symfony\CircuitBreaker\Model\CallResult;
use Tourze\Symfony\CircuitBreaker\Model\CircuitBreakerState;
use Tourze\Symfony\CircuitBreaker\Model\MetricsSnapshot;

/**
 * 链式存储实现
 *
 * 提供故障转移功能，当主存储不可用时自动切换到备用存储
 */
#[WithMonologChannel(channel: 'circuit_breaker')]
final class ChainedStorage implements CircuitBreakerStorageInterface
{
    private const MAX_FAILURES = 3;
    private const RETRY_AFTER = 60;

    /**
     * @var array<CircuitBreakerStorageInterface>
     */
    private array $storages;

    private ?CircuitBreakerStorageInterface $activeStorage = null;

    /**
     * @var array<string, int> 存储失败计数
     */
    private array $failureCounts = [];

    /**
     * @var array<string, int> 最后失败时间
     */
    private array $lastFailureTime = []; // 60秒后重试

    public function __construct(
        CircuitBreakerStorageInterface $primaryStorage,
        CircuitBreakerStorageInterface $secondaryStorage,
        CircuitBreakerStorageInterface $fallbackStorage,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->storages = [
            $primaryStorage,
            $secondaryStorage,
            $fallbackStorage,
        ];
    }

    public function getState(string $name): CircuitBreakerState
    {
        return $this->executeWithFallback(
            fn (CircuitBreakerStorageInterface $storage) => $storage->getState($name),
            new CircuitBreakerState()
        );
    }

    /**
     * 执行操作并在失败时进行故障转移
     *
     * @template T
     *
     * @param callable(CircuitBreakerStorageInterface): T $operation
     * @param T                                           $default
     *
     * @return T
     */
    private function executeWithFallback(callable $operation, mixed $default): mixed
    {
        foreach ($this->storages as $storage) {
            if (!$this->isStorageHealthy($storage)) {
                continue;
            }

            try {
                $result = $operation($storage);

                // 操作成功，设置为活跃存储
                $this->activeStorage = $storage;

                // 重置失败计数
                $storageClass = get_class($storage);
                unset($this->failureCounts[$storageClass], $this->lastFailureTime[$storageClass]);

                return $result;
            } catch (\Throwable $e) {
                $this->handleStorageFailure($storage, $e);
            }
        }

        // 所有存储都失败，返回默认值
        $this->logger->error('All storages failed, returning default value');

        return $default;
    }

    /**
     * 检查存储是否健康
     */
    private function isStorageHealthy(CircuitBreakerStorageInterface $storage): bool
    {
        $storageClass = get_class($storage);

        // 内存存储始终健康
        if ($storage instanceof MemoryStorage) {
            return true;
        }

        // 检查失败次数
        $failures = $this->failureCounts[$storageClass] ?? 0;
        if ($failures >= self::MAX_FAILURES) {
            // 检查是否可以重试
            $lastFailure = $this->lastFailureTime[$storageClass] ?? 0;
            if (time() - $lastFailure < self::RETRY_AFTER) {
                return false;
            }

            // 重置计数器，允许重试
            unset($this->failureCounts[$storageClass], $this->lastFailureTime[$storageClass]);
        }

        // 检查存储是否可用
        try {
            return $storage->isAvailable();
        } catch (\Throwable $e) {
            $this->logger->warning('Storage availability check failed', [
                'storage' => $storageClass,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * 处理存储失败
     */
    private function handleStorageFailure(CircuitBreakerStorageInterface $storage, \Throwable $e): void
    {
        $storageClass = get_class($storage);

        // 增加失败计数
        $this->failureCounts[$storageClass] = ($this->failureCounts[$storageClass] ?? 0) + 1;
        $this->lastFailureTime[$storageClass] = time();

        $this->logger->warning('Storage operation failed', [
            'storage' => $storageClass,
            'error' => $e->getMessage(),
            'failures' => $this->failureCounts[$storageClass],
            'exception' => $e,
        ]);

        // 如果当前活跃存储失败，清除它
        if ($this->activeStorage === $storage) {
            $this->activeStorage = null;
        }
    }

    public function saveState(string $name, CircuitBreakerState $state): bool
    {
        return $this->executeWithFallback(
            fn (CircuitBreakerStorageInterface $storage) => $storage->saveState($name, $state),
            false
        );
    }

    public function recordCall(string $name, CallResult $result): void
    {
        $this->executeWithFallback(
            function (CircuitBreakerStorageInterface $storage) use ($name, $result) {
                $storage->recordCall($name, $result);

                return true;
            },
            null
        );
    }

    public function getMetricsSnapshot(string $name, int $windowSize): MetricsSnapshot
    {
        return $this->executeWithFallback(
            fn (CircuitBreakerStorageInterface $storage) => $storage->getMetricsSnapshot($name, $windowSize),
            new MetricsSnapshot()
        );
    }

    public function getAllCircuitNames(): array
    {
        $allNames = [];

        // 从所有可用的存储中收集熔断器名称
        foreach ($this->storages as $storage) {
            if (!$this->isStorageHealthy($storage)) {
                continue;
            }

            try {
                $names = $storage->getAllCircuitNames();
                $allNames = array_merge($allNames, $names);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to get circuit names from storage', [
                    'storage' => get_class($storage),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 去重并返回
        return array_unique($allNames);
    }

    public function deleteCircuit(string $name): void
    {
        // 在所有存储上执行删除，不管是否可用
        foreach ($this->storages as $storage) {
            try {
                $storage->deleteCircuit($name);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to delete circuit from storage', [
                    'storage' => get_class($storage),
                    'circuit' => $name,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function acquireLock(string $name, string $token, int $ttl): bool
    {
        return $this->executeWithFallback(
            fn (CircuitBreakerStorageInterface $storage) => $storage->acquireLock($name, $token, $ttl),
            false
        );
    }

    public function releaseLock(string $name, string $token): bool
    {
        return $this->executeWithFallback(
            fn (CircuitBreakerStorageInterface $storage) => $storage->releaseLock($name, $token),
            false
        );
    }

    public function isAvailable(): bool
    {
        // 只要有一个存储可用就返回true
        foreach ($this->storages as $storage) {
            if ($this->isStorageHealthy($storage)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 获取当前活跃的存储
     */
    public function getActiveStorage(): ?CircuitBreakerStorageInterface
    {
        return $this->activeStorage;
    }

    /**
     * 获取当前活跃存储的类型
     */
    public function getActiveStorageType(): string
    {
        if (null === $this->activeStorage) {
            return 'none';
        }

        return match (true) {
            $this->activeStorage instanceof RedisAtomicStorage => 'redis',
            $this->activeStorage instanceof DoctrineStorage => 'doctrine',
            $this->activeStorage instanceof MemoryStorage => 'memory',
            default => 'unknown',
        };
    }
}
