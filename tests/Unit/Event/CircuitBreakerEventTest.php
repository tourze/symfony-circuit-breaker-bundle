<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Unit\Event;

use PHPUnit\Framework\TestCase;
use Tourze\Symfony\CircuitBreaker\Event\CircuitClosedEvent;

class CircuitBreakerEventTest extends TestCase
{
    public function testConstructor_setsPropertiesCorrectly(): void
    {
        $circuitName = 'test-circuit';
        $event = new CircuitClosedEvent($circuitName);
        
        $this->assertEquals($circuitName, $event->getCircuitName());
    }
    
    public function testGetCircuitName_returnsCorrectName(): void
    {
        $circuitName = 'another-circuit';
        $event = new CircuitClosedEvent($circuitName);
        
        $this->assertEquals($circuitName, $event->getCircuitName());
    }
}