<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tourze\Symfony\CircuitBreaker\Model\CallResult;

/**
 * @internal
 */
#[CoversClass(CallResult::class)]
final class CallResultTest extends TestCase
{
    public function testSuccessfulCall(): void
    {
        $result = new CallResult(
            success: true,
            duration: 100.5,
            timestamp: 1234567890
        );

        $this->assertTrue($result->isSuccess());
        $this->assertEquals(100.5, $result->getDuration());
        $this->assertEquals(1234567890, $result->getTimestamp());
        $this->assertNull($result->getException());
    }

    public function testFailedCall(): void
    {
        $exception = new \RuntimeException('Test error');
        $result = new CallResult(
            success: false,
            duration: 200.0,
            timestamp: 1234567890,
            exception: $exception
        );

        $this->assertFalse($result->isSuccess());
        $this->assertEquals(200.0, $result->getDuration());
        $this->assertEquals(1234567890, $result->getTimestamp());
        $this->assertSame($exception, $result->getException());
    }

    public function testIsSlowCall(): void
    {
        $result = new CallResult(
            success: true,
            duration: 1500.0,
            timestamp: 1234567890
        );

        $this->assertTrue($result->isSlowCall(1000.0));
        $this->assertFalse($result->isSlowCall(2000.0));
    }

    public function testToString(): void
    {
        $successResult = new CallResult(
            success: true,
            duration: 100.5,
            timestamp: 1234567890
        );

        $this->assertEquals('success:100.50', $successResult->toString());

        $failureResult = new CallResult(
            success: false,
            duration: 200.0,
            timestamp: 1234567890
        );

        $this->assertEquals('failure:200.00', $failureResult->toString());
    }

    public function testFromString(): void
    {
        $successResult = CallResult::fromString('success:100.50', 1234567890);

        $this->assertTrue($successResult->isSuccess());
        $this->assertEquals(100.50, $successResult->getDuration());
        $this->assertEquals(1234567890, $successResult->getTimestamp());

        $failureResult = CallResult::fromString('failure:200.00', 1234567890);

        $this->assertFalse($failureResult->isSuccess());
        $this->assertEquals(200.00, $failureResult->getDuration());
        $this->assertEquals(1234567890, $failureResult->getTimestamp());
    }
}
