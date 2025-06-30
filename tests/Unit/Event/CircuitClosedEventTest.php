<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Unit\Event;

use PHPUnit\Framework\TestCase;
use Tourze\Symfony\CircuitBreaker\Event\CircuitClosedEvent;

class CircuitClosedEventTest extends TestCase
{
    public function testConstructor_setsPropertiesCorrectly(): void
    {
        $circuitName = 'test-circuit';
        $event = new CircuitClosedEvent($circuitName);
        
        $this->assertEquals($circuitName, $event->getCircuitName());
    }
    
    public function testInheritsFromCircuitBreakerEvent(): void
    {
        $event = new CircuitClosedEvent('test');
        
        $this->assertInstanceOf(\Tourze\Symfony\CircuitBreaker\Event\CircuitBreakerEvent::class, $event);
    }
}