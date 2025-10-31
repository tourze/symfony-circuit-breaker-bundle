<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Strategy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tourze\PHPUnitSymfonyKernelTest\AbstractIntegrationTestCase;
use Tourze\Symfony\CircuitBreaker\Strategy\CircuitBreakerStrategyInterface;
use Tourze\Symfony\CircuitBreaker\Strategy\StrategyManager;

/**
 * @internal
 */
#[CoversClass(StrategyManager::class)]
#[RunTestsInSeparateProcesses]
final class StrategyManagerTest extends AbstractIntegrationTestCase
{
    protected function onSetUp(): void
    {
        // No setup required for this test
    }

    private function getStrategyManager(): StrategyManager
    {
        return self::getService(StrategyManager::class);
    }

    public function testGetStrategyForConfigWithFailureRateStrategyReturnsCorrectStrategy(): void
    {
        $strategyManager = $this->getStrategyManager();
        $config = [
            'strategy' => 'failure_rate',
        ];

        $strategy = $strategyManager->getStrategyForConfig($config);

        $this->assertInstanceOf(CircuitBreakerStrategyInterface::class, $strategy);
    }

    public function testGetStrategyWithValidNameReturnsStrategy(): void
    {
        $strategyManager = $this->getStrategyManager();
        $strategy = $strategyManager->getStrategy('failure_rate');

        $this->assertInstanceOf(CircuitBreakerStrategyInterface::class, $strategy);
    }

    public function testGetStrategyWithInvalidNameReturnsNull(): void
    {
        $strategyManager = $this->getStrategyManager();
        $strategy = $strategyManager->getStrategy('unknown_strategy');

        $this->assertNull($strategy);
    }

    public function testHasStrategyWithValidNameReturnsTrue(): void
    {
        $strategyManager = $this->getStrategyManager();
        $result = $strategyManager->hasStrategy('failure_rate');

        $this->assertTrue($result);
    }

    public function testHasStrategyWithInvalidNameReturnsFalse(): void
    {
        $strategyManager = $this->getStrategyManager();
        $result = $strategyManager->hasStrategy('unknown_strategy');

        $this->assertFalse($result);
    }

    public function testGetAvailableStrategiesReturnsArrayOfStrategies(): void
    {
        $strategyManager = $this->getStrategyManager();
        $strategies = $strategyManager->getAvailableStrategies();

        $this->assertContains('failure_rate', $strategies);
        $this->assertContains('consecutive_failure', $strategies);
        $this->assertContains('slow_call', $strategies);
    }

    public function testRegisterStrategyAddsNewStrategy(): void
    {
        // Create a mock strategy
        $customStrategy = $this->createMock(CircuitBreakerStrategyInterface::class);
        $customStrategy->method('getName')->willReturn('custom_strategy');

        $strategyManager = $this->getStrategyManager();

        // Register the custom strategy
        $strategyManager->registerStrategy($customStrategy);

        // Verify it was registered
        $this->assertTrue($strategyManager->hasStrategy('custom_strategy'));
        $this->assertSame($customStrategy, $strategyManager->getStrategy('custom_strategy'));
        $this->assertContains('custom_strategy', $strategyManager->getAvailableStrategies());
    }

    public function testRegisterStrategyOverwritesExistingStrategy(): void
    {
        // Create a custom strategy with the same name as an existing one
        $customStrategy = $this->createMock(CircuitBreakerStrategyInterface::class);
        $customStrategy->method('getName')->willReturn('failure_rate');

        $strategyManager = $this->getStrategyManager();

        // Get the original strategy
        $originalStrategy = $strategyManager->getStrategy('failure_rate');

        // Register the custom strategy (should overwrite)
        $strategyManager->registerStrategy($customStrategy);

        // Verify it was overwritten
        $this->assertTrue($strategyManager->hasStrategy('failure_rate'));
        $this->assertSame($customStrategy, $strategyManager->getStrategy('failure_rate'));
        $this->assertNotSame($originalStrategy, $strategyManager->getStrategy('failure_rate'));
    }
}
