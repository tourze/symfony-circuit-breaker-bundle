<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Unit\Event;

use PHPUnit\Framework\TestCase;
use Tourze\Symfony\CircuitBreaker\Event\CircuitOpenedEvent;

class CircuitOpenedEventTest extends TestCase
{
    public function testConstructor_setsPropertiesCorrectly(): void
    {
        $circuitName = 'test-circuit';
        $failureRate = 75.5;
        $event = new CircuitOpenedEvent($circuitName, $failureRate);
        
        $this->assertEquals($circuitName, $event->getCircuitName());
        $this->assertEquals($failureRate, $event->getFailureRate());
    }
    
    public function testInheritsFromCircuitBreakerEvent(): void
    {
        $event = new CircuitOpenedEvent('test', 50.0);
        
        $this->assertInstanceOf(\Tourze\Symfony\CircuitBreaker\Event\CircuitBreakerEvent::class, $event);
    }
}