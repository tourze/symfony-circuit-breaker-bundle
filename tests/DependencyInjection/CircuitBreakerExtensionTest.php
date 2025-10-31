<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\DependencyInjection;

use PHPUnit\Framework\Attributes\CoversClass;
use Tourze\PHPUnitSymfonyUnitTest\AbstractDependencyInjectionExtensionTestCase;
use Tourze\Symfony\CircuitBreaker\DependencyInjection\CircuitBreakerExtension;

/**
 * @internal
 */
#[CoversClass(CircuitBreakerExtension::class)]
final class CircuitBreakerExtensionTest extends AbstractDependencyInjectionExtensionTestCase
{
    protected function getExtensionClass(): string
    {
        return CircuitBreakerExtension::class;
    }

    public function testGetAlias(): void
    {
        $extension = new CircuitBreakerExtension();
        $this->assertEquals('circuit_breaker', $extension->getAlias());
    }
}
