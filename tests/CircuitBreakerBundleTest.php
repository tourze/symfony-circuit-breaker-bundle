<?php

declare(strict_types=1);

namespace CircuitBreakerBundle\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tourze\PHPUnitSymfonyKernelTest\AbstractBundleTestCase;
use Tourze\Symfony\CircuitBreaker\CircuitBreakerBundle;

/**
 * @internal
 */
#[CoversClass(CircuitBreakerBundle::class)]
#[RunTestsInSeparateProcesses]
final class CircuitBreakerBundleTest extends AbstractBundleTestCase
{
    // 此 Bundle 不使用传统的容器扩展，而是依赖 #[Autoconfigure] 注解
    // 基类的 testBundleHasContainerExtension 测试会失败，但这是预期的
}
