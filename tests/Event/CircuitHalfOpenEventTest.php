<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use Tourze\PHPUnitSymfonyUnitTest\AbstractEventTestCase;
use Tourze\Symfony\CircuitBreaker\Event\CircuitBreakerEvent;
use Tourze\Symfony\CircuitBreaker\Event\CircuitHalfOpenEvent;

/**
 * @internal
 */
#[CoversClass(CircuitHalfOpenEvent::class)]
final class CircuitHalfOpenEventTest extends AbstractEventTestCase
{
    public function testConstructorSetsPropertiesCorrectly(): void
    {
        $circuitName = 'test-circuit';
        $event = new CircuitHalfOpenEvent($circuitName);

        $this->assertEquals($circuitName, $event->getCircuitName());
    }

    public function testInheritsFromCircuitBreakerEvent(): void
    {
        $event = new CircuitHalfOpenEvent('test');

        $this->assertInstanceOf(CircuitBreakerEvent::class, $event);
    }
}
