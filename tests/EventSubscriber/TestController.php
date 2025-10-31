<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\EventSubscriber;

use Tourze\Symfony\CircuitBreaker\Attribute\CircuitBreaker;

/**
 * 使用示例控制器进行测试
 */
class TestController
{
    #[CircuitBreaker(name: 'test.circuit')]
    public function circuitProtectedAction(): string
    {
        return 'protected result';
    }

    #[CircuitBreaker(name: 'test.circuit.with.fallback', fallbackMethod: 'fallbackAction')]
    public function actionWithFallback(): string
    {
        return 'original result';
    }

    public function fallbackAction(): string
    {
        return 'fallback result';
    }

    public function nonProtectedAction(): string
    {
        return 'non protected result';
    }
}
