<?php

namespace Tourze\Symfony\CircuitBreaker\Strategy;

use Psr\Log\LoggerInterface;

/**
 * 策略管理器
 *
 * 管理和选择熔断器策略
 */
class StrategyManager
{
    /**
     * @var array<string, CircuitBreakerStrategyInterface>
     */
    private array $strategies = [];

    public function __construct(
        private readonly LoggerInterface $logger
    ) {
        // 注册默认策略
        $this->registerStrategy(new FailureRateStrategy());
        $this->registerStrategy(new SlowCallStrategy());
        $this->registerStrategy(new ConsecutiveFailureStrategy());
    }

    /**
     * 注册策略
     */
    public function registerStrategy(CircuitBreakerStrategyInterface $strategy): void
    {
        $this->strategies[$strategy->getName()] = $strategy;
        
        $this->logger->debug('Registered circuit breaker strategy', [
            'strategy' => $strategy->getName(),
        ]);
    }

    /**
     * 根据配置获取策略
     */
    public function getStrategyForConfig(array $config): CircuitBreakerStrategyInterface
    {
        $strategyName = $config['strategy'] ?? 'failure_rate';
        $strategy = $this->getStrategy($strategyName);

        if ($strategy === null) {
            $this->logger->warning('Unknown strategy, falling back to failure_rate', [
                'requested_strategy' => $strategyName,
            ]);

            return $this->strategies['failure_rate'];
        }

        return $strategy;
    }

    /**
     * 获取策略
     */
    public function getStrategy(string $name): ?CircuitBreakerStrategyInterface
    {
        return $this->strategies[$name] ?? null;
    }

    /**
     * 获取所有可用策略名称
     *
     * @return array<string>
     */
    public function getAvailableStrategies(): array
    {
        return array_keys($this->strategies);
    }

    /**
     * 检查是否存在策略
     */
    public function hasStrategy(string $name): bool
    {
        return isset($this->strategies[$name]);
    }
}