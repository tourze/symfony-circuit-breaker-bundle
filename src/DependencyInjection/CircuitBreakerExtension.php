<?php

declare(strict_types=1);

namespace Tourze\Symfony\CircuitBreaker\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tourze\SymfonyDependencyServiceLoader\AppendDoctrineConnectionExtension;

class CircuitBreakerExtension extends AppendDoctrineConnectionExtension
{
    protected function getConfigDir(): string
    {
        return __DIR__ . '/../Resources/config';
    }

    protected function getDoctrineConnectionName(): string
    {
        return 'circuit_breaker';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        // 调用父类的 load 方法确保环境特定配置被正确加载
        parent::load($configs, $container);
    }
}
