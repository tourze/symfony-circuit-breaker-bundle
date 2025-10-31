<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use Tourze\PHPUnitSymfonyUnitTest\AbstractEventTestCase;
use Tourze\Symfony\CircuitBreaker\Event\CircuitBreakerEvent;
use Tourze\Symfony\CircuitBreaker\Event\CircuitClosedEvent;

/**
 * @internal
 */
#[CoversClass(CircuitClosedEvent::class)]
final class CircuitClosedEventTest extends AbstractEventTestCase
{
    public function testConstructorSetsPropertiesCorrectly(): void
    {
        $circuitName = 'test-circuit';
        $event = new CircuitClosedEvent($circuitName);

        $this->assertEquals($circuitName, $event->getCircuitName());
    }

    public function testInheritsFromCircuitBreakerEvent(): void
    {
        $event = new CircuitClosedEvent('test');

        $this->assertInstanceOf(CircuitBreakerEvent::class, $event);
    }
}
