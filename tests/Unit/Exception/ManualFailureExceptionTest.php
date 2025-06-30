<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Unit\Exception;

use PHPUnit\Framework\TestCase;
use Tourze\Symfony\CircuitBreaker\Exception\ManualFailureException;

class ManualFailureExceptionTest extends TestCase
{
    public function testConstructor_setsMessageCorrectly(): void
    {
        $message = 'Manual failure mark';
        $exception = new ManualFailureException($message);
        
        $this->assertEquals($message, $exception->getMessage());
    }
    
    public function testInheritsFromRuntimeException(): void
    {
        $exception = new ManualFailureException('test');
        
        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }
}