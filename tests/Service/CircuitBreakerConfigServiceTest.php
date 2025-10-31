<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tourze\PHPUnitSymfonyKernelTest\AbstractIntegrationTestCase;
use Tourze\Symfony\CircuitBreaker\Service\CircuitBreakerConfigService;

/**
 * @internal
 */
#[CoversClass(CircuitBreakerConfigService::class)]
#[RunTestsInSeparateProcesses]
final class CircuitBreakerConfigServiceTest extends AbstractIntegrationTestCase
{
    private CircuitBreakerConfigService $configService;

    protected function onSetUp(): void
    {
        $this->configService = self::getService(CircuitBreakerConfigService::class);
    }

    public function testGetCircuitConfigReturnsDefaultConfig(): void
    {
        $config = $this->configService->getCircuitConfig('test-circuit');

        $this->assertArrayHasKey('failure_rate_threshold', $config);
        $this->assertArrayHasKey('minimum_number_of_calls', $config);
        $this->assertArrayHasKey('strategy', $config);
    }

    public function testGetConfigReturnsFullConfig(): void
    {
        $config = $this->configService->getConfig();

        $this->assertArrayHasKey('default_circuit', $config);
        $this->assertArrayHasKey('storage', $config);
        $this->assertArrayHasKey('circuits', $config);
    }
}
