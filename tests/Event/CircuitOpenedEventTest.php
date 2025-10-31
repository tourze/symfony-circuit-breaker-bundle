<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use Tourze\PHPUnitSymfonyUnitTest\AbstractEventTestCase;
use Tourze\Symfony\CircuitBreaker\Event\CircuitBreakerEvent;
use Tourze\Symfony\CircuitBreaker\Event\CircuitOpenedEvent;

/**
 * @internal
 */
#[CoversClass(CircuitOpenedEvent::class)]
final class CircuitOpenedEventTest extends AbstractEventTestCase
{
    public function testConstructorSetsPropertiesCorrectly(): void
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

        $this->assertInstanceOf(CircuitBreakerEvent::class, $event);
    }
}
