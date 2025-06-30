<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Unit\Event;

use PHPUnit\Framework\TestCase;
use Tourze\Symfony\CircuitBreaker\Event\CircuitHalfOpenEvent;

class CircuitHalfOpenEventTest extends TestCase
{
    public function testConstructor_setsPropertiesCorrectly(): void
    {
        $circuitName = 'test-circuit';
        $event = new CircuitHalfOpenEvent($circuitName);
        
        $this->assertEquals($circuitName, $event->getCircuitName());
    }
    
    public function testInheritsFromCircuitBreakerEvent(): void
    {
        $event = new CircuitHalfOpenEvent('test');
        
        $this->assertInstanceOf(\Tourze\Symfony\CircuitBreaker\Event\CircuitBreakerEvent::class, $event);
    }
}