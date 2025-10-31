<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Strategy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tourze\Symfony\CircuitBreaker\Model\MetricsSnapshot;
use Tourze\Symfony\CircuitBreaker\Strategy\SlowCallStrategy;

/**
 * @internal
 */
#[CoversClass(SlowCallStrategy::class)]
final class SlowCallStrategyTest extends TestCase
{
    private SlowCallStrategy $strategy;

    public function testGetName(): void
    {
        $this->assertEquals('slow_call', $this->strategy->getName());
    }

    public function testShouldOpenWithInsufficientCalls(): void
    {
        $metrics = new MetricsSnapshot(
            totalCalls: 5,
            slowCalls: 5
        );

        $config = [
            'minimum_number_of_calls' => 10,
            'slow_call_rate_threshold' => 50,
        ];

        $this->assertFalse($this->strategy->shouldOpen($metrics, $config));
    }

    public function testShouldOpenWithHighSlowCallRate(): void
    {
        $metrics = new MetricsSnapshot(
            totalCalls: 100,
            slowCalls: 60,
            successCalls: 40,
            failedCalls: 0
        );

        $config = [
            'minimum_number_of_calls' => 10,
            'slow_call_rate_threshold' => 50,
        ];

        $this->assertTrue($this->strategy->shouldOpen($metrics, $config));
    }

    public function testShouldOpenWithLowSlowCallRate(): void
    {
        $metrics = new MetricsSnapshot(
            totalCalls: 100,
            slowCalls: 30,
            successCalls: 70,
            failedCalls: 0
        );

        $config = [
            'minimum_number_of_calls' => 10,
            'slow_call_rate_threshold' => 50,
        ];

        $this->assertFalse($this->strategy->shouldOpen($metrics, $config));
    }

    public function testShouldOpenExactlyAtThreshold(): void
    {
        $metrics = new MetricsSnapshot(
            totalCalls: 100,
            slowCalls: 50,
            successCalls: 50,
            failedCalls: 0
        );

        $config = [
            'minimum_number_of_calls' => 10,
            'slow_call_rate_threshold' => 50,
        ];

        $this->assertTrue($this->strategy->shouldOpen($metrics, $config));
    }

    public function testShouldCloseWithInsufficientCalls(): void
    {
        $metrics = new MetricsSnapshot(
            totalCalls: 3,
            slowCalls: 0,
            successCalls: 3
        );

        $config = [
            'permitted_number_of_calls_in_half_open_state' => 5,
            'slow_call_rate_threshold' => 50,
        ];

        $this->assertFalse($this->strategy->shouldClose($metrics, $config));
    }

    public function testShouldCloseWithLowSlowCallRate(): void
    {
        $metrics = new MetricsSnapshot(
            totalCalls: 10,
            slowCalls: 2,
            successCalls: 8,
            failedCalls: 0
        );

        $config = [
            'permitted_number_of_calls_in_half_open_state' => 5,
            'slow_call_rate_threshold' => 50,
        ];

        $this->assertTrue($this->strategy->shouldClose($metrics, $config));
    }

    public function testShouldCloseWithHighSlowCallRate(): void
    {
        $metrics = new MetricsSnapshot(
            totalCalls: 10,
            slowCalls: 7,
            successCalls: 3,
            failedCalls: 0
        );

        $config = [
            'permitted_number_of_calls_in_half_open_state' => 5,
            'slow_call_rate_threshold' => 50,
        ];

        $this->assertFalse($this->strategy->shouldClose($metrics, $config));
    }

    public function testShouldOpenWithMissingConfigUsesDefaults(): void
    {
        $metrics = new MetricsSnapshot(
            totalCalls: 100,
            slowCalls: 60
        );

        $config = []; // Missing configuration

        // Default minimum_number_of_calls is 10
        // Default slow_call_rate_threshold is 50
        $this->assertTrue($this->strategy->shouldOpen($metrics, $config));
    }

    public function testShouldOpenWithZeroTotalCalls(): void
    {
        $metrics = new MetricsSnapshot(
            totalCalls: 0,
            slowCalls: 0
        );

        $config = [
            'minimum_number_of_calls' => 10,
            'slow_call_rate_threshold' => 50,
        ];

        $this->assertFalse($this->strategy->shouldOpen($metrics, $config));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->strategy = new SlowCallStrategy();
    }
}
