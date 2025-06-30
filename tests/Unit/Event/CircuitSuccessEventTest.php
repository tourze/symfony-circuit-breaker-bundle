<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Unit\Event;

use PHPUnit\Framework\TestCase;
use Tourze\Symfony\CircuitBreaker\Event\CircuitSuccessEvent;

class CircuitSuccessEventTest extends TestCase
{
    public function testConstructor_setsPropertiesCorrectly(): void
    {
        $circuitName = 'test-circuit';
        $event = new CircuitSuccessEvent($circuitName);
        
        $this->assertEquals($circuitName, $event->getCircuitName());
    }
    
    public function testInheritsFromCircuitBreakerEvent(): void
    {
        $event = new CircuitSuccessEvent('test');
        
        $this->assertInstanceOf(\Tourze\Symfony\CircuitBreaker\Event\CircuitBreakerEvent::class, $event);
    }
}