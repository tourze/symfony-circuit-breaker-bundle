<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use Tourze\PHPUnitSymfonyUnitTest\AbstractEventTestCase;
use Tourze\Symfony\CircuitBreaker\Event\CircuitBreakerEvent;
use Tourze\Symfony\CircuitBreaker\Event\CircuitClosedEvent;

/**
 * @internal
 */
#[CoversClass(CircuitBreakerEvent::class)]
final class CircuitBreakerEventTest extends AbstractEventTestCase
{
    public function testConstructorSetsPropertiesCorrectly(): void
    {
        $circuitName = 'test-circuit';
        $event = new CircuitClosedEvent($circuitName);

        $this->assertEquals($circuitName, $event->getCircuitName());
    }

    public function testGetCircuitNameReturnsCorrectName(): void
    {
        $circuitName = 'another-circuit';
        $event = new CircuitClosedEvent($circuitName);

        $this->assertEquals($circuitName, $event->getCircuitName());
    }
}
