<?php

namespace Tourze\Symfony\CircuitBreaker\Storage;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Redis;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Tourze\Symfony\CircuitBreaker\Model\CircuitBreakerMetrics;
use Tourze\Symfony\CircuitBreaker\Model\CircuitBreakerState;

/**
 * Redis存储实现
 *
 * 使用Redis存储熔断器状态和指标数据
 */
#[AsAlias(CircuitBreakerStorageInterface::class)]
class RedisStorage implements CircuitBreakerStorageInterface
{
    /**
     * 键前缀 - 状态
     */
    private const KEY_PREFIX_STATE = 'circuit_breaker:state:';

    /**
     * 键前缀 - 指标
     */
    private const KEY_PREFIX_METRICS = 'circuit_breaker:metrics:';

    /**
     * 键集合 - 所有熔断器
     */
    private const KEY_SET_CIRCUITS = 'circuit_breaker:circuits';

    /**
     * @param Redis $redis Redis客户端
     * @param LoggerInterface $logger 日志记录器
     * @param int $stateTtl 状态的TTL（秒）
     * @param int $metricsTtl 指标的TTL（秒）
     */
    public function __construct(
        #[Autowire(service: 'snc_redis.default')] private readonly Redis $redis,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly int $stateTtl = 86400,
        private readonly int $metricsTtl = 3600
    ) {
    }

    public function getState(string $name): CircuitBreakerState
    {
        $key = self::KEY_PREFIX_STATE . $name;
        $data = $this->redis->get($key);

        if ($data === false) {
            return new CircuitBreakerState();
        }

        try {
            $stateData = json_decode($data, true);
            return CircuitBreakerState::fromArray($stateData);
        } catch (\Throwable $e) {
            $this->logger->warning('无法解析熔断器状态，使用默认状态: {error}', [
                'circuit' => $name,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            return new CircuitBreakerState();
        }
    }

    public function saveState(string $name, CircuitBreakerState $state): void
    {
        $key = self::KEY_PREFIX_STATE . $name;
        $data = json_encode($state->toArray());

        $this->redis->set($key, $data, ['ex' => $this->stateTtl]);

        // 将熔断器名称添加到集合中
        $this->redis->sAdd(self::KEY_SET_CIRCUITS, $name);
    }

    public function getMetrics(string $name): CircuitBreakerMetrics
    {
        $key = self::KEY_PREFIX_METRICS . $name;
        $data = $this->redis->get($key);

        if ($data === false) {
            return new CircuitBreakerMetrics();
        }

        try {
            $metricsData = json_decode($data, true);
            $metrics = new CircuitBreakerMetrics();

            for ($i = 0; $i < ($metricsData['numberOfCalls'] ?? 0); $i++) {
                $metrics->incrementCalls();
            }

            for ($i = 0; $i < ($metricsData['numberOfSuccessfulCalls'] ?? 0); $i++) {
                $metrics->incrementSuccessfulCalls();
            }

            for ($i = 0; $i < ($metricsData['numberOfFailedCalls'] ?? 0); $i++) {
                $metrics->incrementFailedCalls();
            }

            for ($i = 0; $i < ($metricsData['notPermittedCalls'] ?? 0); $i++) {
                $metrics->incrementNotPermittedCalls();
            }

            return $metrics;
        } catch (\Throwable $e) {
            $this->logger->warning('无法解析熔断器指标，使用默认指标: {error}', [
                'circuit' => $name,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            return new CircuitBreakerMetrics();
        }
    }

    public function saveMetrics(string $name, CircuitBreakerMetrics $metrics): void
    {
        $key = self::KEY_PREFIX_METRICS . $name;
        $data = json_encode($metrics->toArray());

        $this->redis->set($key, $data, ['ex' => $this->metricsTtl]);

        // 将熔断器名称添加到集合中
        $this->redis->sAdd(self::KEY_SET_CIRCUITS, $name);
    }

    public function getAllCircuitNames(): array
    {
        return $this->redis->sMembers(self::KEY_SET_CIRCUITS) ?: [];
    }

    public function deleteCircuit(string $name): void
    {
        $stateKey = self::KEY_PREFIX_STATE . $name;
        $metricsKey = self::KEY_PREFIX_METRICS . $name;

        $this->redis->del($stateKey, $metricsKey);
        $this->redis->sRem(self::KEY_SET_CIRCUITS, $name);
    }
}
