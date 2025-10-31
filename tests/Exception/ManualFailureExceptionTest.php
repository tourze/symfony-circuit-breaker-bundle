<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use Tourze\PHPUnitBase\AbstractExceptionTestCase;
use Tourze\Symfony\CircuitBreaker\Exception\ManualFailureException;

/**
 * @internal
 */
#[CoversClass(ManualFailureException::class)]
final class ManualFailureExceptionTest extends AbstractExceptionTestCase
{
    public function testConstructorSetsMessageCorrectly(): void
    {
        $message = 'Manual failure mark';
        $exception = new ManualFailureException($message);

        $this->assertEquals($message, $exception->getMessage());
    }

    public function testInheritsFromRuntimeException(): void
    {
        $exception = new ManualFailureException('test');

        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }
}
