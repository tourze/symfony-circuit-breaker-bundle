<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tourze\PHPUnitSymfonyKernelTest\AbstractCommandTestCase;
use Tourze\Symfony\CircuitBreaker\Command\CircuitBreakerStatusCommand;

/**
 * @internal
 */
#[CoversClass(CircuitBreakerStatusCommand::class)]
#[RunTestsInSeparateProcesses]
final class CircuitBreakerStatusCommandTest extends AbstractCommandTestCase
{
    protected function onSetUp(): void
    {
        // 不需要特殊设置
    }

    protected function getCommandTester(): CommandTester
    {
        $command = self::getService(CircuitBreakerStatusCommand::class);

        return new CommandTester($command);
    }

    public function testCommandExists(): void
    {
        $this->assertEquals('circuit-breaker:status', CircuitBreakerStatusCommand::NAME);
    }

    public function testCommandCanExecute(): void
    {
        $commandTester = $this->getCommandTester();
        $commandTester->execute([]);

        $this->assertEquals(Command::SUCCESS, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('熔断器', $output);
    }

    public function testArgumentService(): void
    {
        $commandTester = $this->getCommandTester();
        $commandTester->execute(['service' => 'test-service']);

        $this->assertEquals(Command::SUCCESS, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertNotEmpty($output);
    }

    public function testOptionReset(): void
    {
        $commandTester = $this->getCommandTester();
        $commandTester->execute(['service' => 'test-service', '--reset' => true]);

        $this->assertEquals(Command::SUCCESS, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('已重置', $output);
    }

    public function testOptionForceOpen(): void
    {
        $commandTester = $this->getCommandTester();
        $commandTester->execute(['service' => 'test-service', '--force-open' => true]);

        $this->assertEquals(Command::SUCCESS, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('已强制打开', $output);
    }

    public function testOptionForceClose(): void
    {
        $commandTester = $this->getCommandTester();
        $commandTester->execute(['service' => 'test-service', '--force-close' => true]);

        $this->assertEquals(Command::SUCCESS, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('已强制关闭', $output);
    }

    public function testOptionHealth(): void
    {
        $commandTester = $this->getCommandTester();
        $commandTester->execute(['--health' => true]);

        $this->assertEquals(Command::SUCCESS, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('健康状态', $output);
    }

    public function testOptionJson(): void
    {
        $commandTester = $this->getCommandTester();
        $commandTester->execute(['--json' => true]);

        $this->assertEquals(Command::SUCCESS, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertJson($output);
    }
}
