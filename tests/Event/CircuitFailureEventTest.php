<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use Tourze\PHPUnitSymfonyUnitTest\AbstractEventTestCase;
use Tourze\Symfony\CircuitBreaker\Event\CircuitBreakerEvent;
use Tourze\Symfony\CircuitBreaker\Event\CircuitFailureEvent;

/**
 * @internal
 */
#[CoversClass(CircuitFailureEvent::class)]
final class CircuitFailureEventTest extends AbstractEventTestCase
{
    public function testConstructorSetsPropertiesCorrectly(): void
    {
        $circuitName = 'test-circuit';
        $exception = new \Exception('Test error');
        $event = new CircuitFailureEvent($circuitName, $exception);

        $this->assertEquals($circuitName, $event->getCircuitName());
        $this->assertEquals($exception, $event->getThrowable());
    }

    public function testGetThrowableReturnsCorrectException(): void
    {
        $exception = new \RuntimeException('Test runtime error');
        $event = new CircuitFailureEvent('test', $exception);

        $this->assertSame($exception, $event->getThrowable());
    }

    public function testInheritsFromCircuitBreakerEvent(): void
    {
        $event = new CircuitFailureEvent('test', new \Exception());

        $this->assertInstanceOf(CircuitBreakerEvent::class, $event);
    }
}
