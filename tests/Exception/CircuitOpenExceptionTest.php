<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use Tourze\PHPUnitBase\AbstractExceptionTestCase;
use Tourze\Symfony\CircuitBreaker\Exception\CircuitOpenException;

/**
 * @internal
 */
#[CoversClass(CircuitOpenException::class)]
final class CircuitOpenExceptionTest extends AbstractExceptionTestCase
{
    public function testConstructorWithCircuitNameSetsPropertiesCorrectly(): void
    {
        $circuitName = 'test-circuit';
        $exception = new CircuitOpenException($circuitName);

        $this->assertEquals($circuitName, $exception->getCircuitName());
        $this->assertStringContainsString($circuitName, $exception->getMessage());
    }

    public function testConstructorWithCustomMessageUsesCustomMessage(): void
    {
        $circuitName = 'test-circuit';
        $customMessage = 'Custom error message';
        $exception = new CircuitOpenException($circuitName, $customMessage);

        $this->assertEquals($customMessage, $exception->getMessage());
        $this->assertEquals($circuitName, $exception->getCircuitName());
    }

    public function testConstructorWithEmptyMessageUsesDefaultMessage(): void
    {
        $circuitName = 'test-circuit';
        $exception = new CircuitOpenException($circuitName, '');

        $this->assertStringContainsString('电路熔断器', $exception->getMessage());
        $this->assertStringContainsString($circuitName, $exception->getMessage());
    }

    public function testInheritsFromRuntimeException(): void
    {
        $exception = new CircuitOpenException('test');

        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }
}
