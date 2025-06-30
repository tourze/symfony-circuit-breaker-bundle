<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Integration\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Tourze\Symfony\CircuitBreaker\Service\CircuitBreakerConfigService;
use Tourze\Symfony\CircuitBreaker\Service\CircuitBreakerRegistry;
use Tourze\Symfony\CircuitBreaker\Service\CircuitBreakerService;
use Tourze\Symfony\CircuitBreaker\Service\MetricsCollector;
use Tourze\Symfony\CircuitBreaker\Service\StateManager;
use Tourze\Symfony\CircuitBreaker\Storage\MemoryStorage;
use Tourze\Symfony\CircuitBreaker\Strategy\StrategyManager;

class CircuitBreakerRegistryTest extends TestCase
{
    private CircuitBreakerRegistry $registry;
    private MemoryStorage $storage;
    
    protected function setUp(): void
    {
        $this->storage = new MemoryStorage();
        $configService = new CircuitBreakerConfigService();
        $eventDispatcher = new EventDispatcher();
        $stateManager = new StateManager($this->storage, $eventDispatcher, new NullLogger());
        $metricsCollector = new MetricsCollector($this->storage, $configService);
        $strategyManager = new StrategyManager(new NullLogger());
        
        $this->registry = new CircuitBreakerRegistry(
            $this->storage,
            $configService,
            $stateManager,
            $metricsCollector
        );
    }
    
    public function testGetCircuitInfo_returnsArray(): void
    {
        $info = $this->registry->getCircuitInfo('test-circuit');
        
        $this->assertArrayHasKey('name', $info);
        $this->assertEquals('test-circuit', $info['name']);
    }
    
    public function testGetAllCircuits_returnsEmptyArrayInitially(): void
    {
        $circuits = $this->registry->getAllCircuits();
        
        $this->assertEmpty($circuits);
    }
}