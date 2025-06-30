<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Unit\Exception;

use PHPUnit\Framework\TestCase;
use Tourze\Symfony\CircuitBreaker\Exception\CircuitOpenException;

class CircuitOpenExceptionTest extends TestCase
{
    public function testConstructor_withCircuitName_setsPropertiesCorrectly(): void
    {
        $circuitName = 'test-circuit';
        $exception = new CircuitOpenException($circuitName);
        
        $this->assertEquals($circuitName, $exception->getCircuitName());
        $this->assertStringContainsString($circuitName, $exception->getMessage());
    }
    
    public function testConstructor_withCustomMessage_usesCustomMessage(): void
    {
        $circuitName = 'test-circuit';
        $customMessage = 'Custom error message';
        $exception = new CircuitOpenException($circuitName, $customMessage);
        
        $this->assertEquals($customMessage, $exception->getMessage());
        $this->assertEquals($circuitName, $exception->getCircuitName());
    }
    
    public function testConstructor_withEmptyMessage_usesDefaultMessage(): void
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