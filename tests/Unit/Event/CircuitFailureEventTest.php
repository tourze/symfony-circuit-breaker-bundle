<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Unit\Event;

use PHPUnit\Framework\TestCase;
use Tourze\Symfony\CircuitBreaker\Event\CircuitFailureEvent;

class CircuitFailureEventTest extends TestCase
{
    public function testConstructor_setsPropertiesCorrectly(): void
    {
        $circuitName = 'test-circuit';
        $exception = new \Exception('Test error');
        $event = new CircuitFailureEvent($circuitName, $exception);
        
        $this->assertEquals($circuitName, $event->getCircuitName());
        $this->assertEquals($exception, $event->getThrowable());
    }
    
    public function testGetThrowable_returnsCorrectException(): void
    {
        $exception = new \RuntimeException('Test runtime error');
        $event = new CircuitFailureEvent('test', $exception);
        
        $this->assertSame($exception, $event->getThrowable());
    }
    
    public function testInheritsFromCircuitBreakerEvent(): void
    {
        $event = new CircuitFailureEvent('test', new \Exception());
        
        $this->assertInstanceOf(\Tourze\Symfony\CircuitBreaker\Event\CircuitBreakerEvent::class, $event);
    }
}