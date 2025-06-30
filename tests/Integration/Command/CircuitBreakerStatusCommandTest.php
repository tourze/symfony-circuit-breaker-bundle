<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Integration\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tourze\Symfony\CircuitBreaker\Command\CircuitBreakerStatusCommand;
use Tourze\Symfony\CircuitBreaker\Service\CircuitBreakerRegistry;

class CircuitBreakerStatusCommandTest extends TestCase
{
    private CircuitBreakerRegistry $registry;
    private CircuitBreakerStatusCommand $command;
    
    protected function setUp(): void
    {
        $circuitBreakerService = $this->createMock(\Tourze\Symfony\CircuitBreaker\Service\CircuitBreakerService::class);
        $this->registry = $this->createMock(CircuitBreakerRegistry::class);
        $this->command = new CircuitBreakerStatusCommand($circuitBreakerService, $this->registry);
    }
    
    public function testExecute_withNoCircuits_showsEmptyMessage(): void
    {
        $this->registry->expects($this->once())
            ->method('getAllCircuitsInfo')
            ->willReturn([]);
            
        $application = new Application();
        $application->add($this->command);
        
        $commandTester = new CommandTester($this->command);
        $commandTester->execute([]);
        
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('暂无任何熔断器数据', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }
    
    public function testExecute_withCircuits_showsCircuitInfo(): void
    {
        $this->registry->expects($this->once())
            ->method('getAllCircuitsInfo')
            ->willReturn([
                'service1' => [
                    'name' => 'service1',
                    'state' => 'closed',
                    'metrics' => [],
                    'storage' => 'memory'
                ],
                'service2' => [
                    'name' => 'service2',
                    'state' => 'open',
                    'metrics' => [],
                    'storage' => 'memory'
                ]
            ]);
            
        $application = new Application();
        $application->add($this->command);
        
        $commandTester = new CommandTester($this->command);
        $commandTester->execute([]);
        
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('service1', $output);
        $this->assertStringContainsString('service2', $output);
        $this->assertEquals(0, $commandTester->getStatusCode());
    }
}