<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Integration\Service;

use PHPUnit\Framework\TestCase;
use Tourze\Symfony\CircuitBreaker\Service\CircuitBreakerConfigService;

class CircuitBreakerConfigServiceTest extends TestCase
{
    private CircuitBreakerConfigService $configService;
    
    protected function setUp(): void
    {
        $this->configService = new CircuitBreakerConfigService();
    }
    
    public function testGetCircuitConfig_returnsDefaultConfig(): void
    {
        $config = $this->configService->getCircuitConfig('test-circuit');
        
        $this->assertArrayHasKey('failure_rate_threshold', $config);
        $this->assertArrayHasKey('minimum_number_of_calls', $config);
        $this->assertArrayHasKey('strategy', $config);
    }
    
    public function testGetConfig_returnsFullConfig(): void
    {
        $config = $this->configService->getConfig();
        
        $this->assertArrayHasKey('default_circuit', $config);
        $this->assertArrayHasKey('storage', $config);
        $this->assertArrayHasKey('circuits', $config);
    }
}