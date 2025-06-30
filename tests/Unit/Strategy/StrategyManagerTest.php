<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Unit\Strategy;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tourze\Symfony\CircuitBreaker\Strategy\CircuitBreakerStrategyInterface;
use Tourze\Symfony\CircuitBreaker\Strategy\StrategyManager;

class StrategyManagerTest extends TestCase
{
    private StrategyManager $strategyManager;
    
    protected function setUp(): void
    {
        $this->strategyManager = new StrategyManager(new NullLogger());
    }
    
    public function testGetStrategyForConfig_withFailureRateStrategy_returnsCorrectStrategy(): void
    {
        $config = [
            'strategy' => 'failure_rate'
        ];
        
        $strategy = $this->strategyManager->getStrategyForConfig($config);
        
        $this->assertInstanceOf(CircuitBreakerStrategyInterface::class, $strategy);
    }
    
    public function testGetStrategy_withValidName_returnsStrategy(): void
    {
        $strategy = $this->strategyManager->getStrategy('failure_rate');
        
        $this->assertInstanceOf(CircuitBreakerStrategyInterface::class, $strategy);
    }
    
    public function testGetStrategy_withInvalidName_returnsNull(): void
    {
        $strategy = $this->strategyManager->getStrategy('unknown_strategy');
        
        $this->assertNull($strategy);
    }
    
    public function testHasStrategy_withValidName_returnsTrue(): void
    {
        $result = $this->strategyManager->hasStrategy('failure_rate');
        
        $this->assertTrue($result);
    }
    
    public function testHasStrategy_withInvalidName_returnsFalse(): void
    {
        $result = $this->strategyManager->hasStrategy('unknown_strategy');
        
        $this->assertFalse($result);
    }
    
    public function testGetAvailableStrategies_returnsArrayOfStrategies(): void
    {
        $strategies = $this->strategyManager->getAvailableStrategies();
        
        $this->assertContains('failure_rate', $strategies);
        $this->assertContains('consecutive_failure', $strategies);
        $this->assertContains('slow_call', $strategies);
    }
}