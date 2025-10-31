<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use Tourze\PHPUnitSymfonyUnitTest\AbstractEventTestCase;
use Tourze\Symfony\CircuitBreaker\Event\CircuitBreakerEvent;
use Tourze\Symfony\CircuitBreaker\Event\CircuitSuccessEvent;

/**
 * @internal
 */
#[CoversClass(CircuitSuccessEvent::class)]
final class CircuitSuccessEventTest extends AbstractEventTestCase
{
    public function testConstructorSetsPropertiesCorrectly(): void
    {
        $circuitName = 'test-circuit';
        $event = new CircuitSuccessEvent($circuitName);

        $this->assertEquals($circuitName, $event->getCircuitName());
    }

    public function testInheritsFromCircuitBreakerEvent(): void
    {
        $event = new CircuitSuccessEvent('test');

        $this->assertInstanceOf(CircuitBreakerEvent::class, $event);
    }
}
