<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Strategy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tourze\Symfony\CircuitBreaker\Model\MetricsSnapshot;
use Tourze\Symfony\CircuitBreaker\Strategy\FailureRateStrategy;

/**
 * @internal
 */
#[CoversClass(FailureRateStrategy::class)]
final class FailureRateStrategyTest extends TestCase
{
    private FailureRateStrategy $strategy;

    public function testGetName(): void
    {
        $this->assertEquals('failure_rate', $this->strategy->getName());
    }

    public function testShouldOpenWithInsufficientCalls(): void
    {
        $metrics = new MetricsSnapshot(
            totalCalls: 5,
            failedCalls: 5
        );

        $config = [
            'minimum_number_of_calls' => 10,
            'failure_rate_threshold' => 50,
        ];

        $this->assertFalse($this->strategy->shouldOpen($metrics, $config));
    }

    public function testShouldOpenWithHighFailureRate(): void
    {
        $metrics = new MetricsSnapshot(
            totalCalls: 100,
            successCalls: 40,
            failedCalls: 60
        );

        $config = [
            'minimum_number_of_calls' => 10,
            'failure_rate_threshold' => 50,
        ];

        $this->assertTrue($this->strategy->shouldOpen($metrics, $config));
    }

    public function testShouldOpenWithLowFailureRate(): void
    {
        $metrics = new MetricsSnapshot(
            totalCalls: 100,
            successCalls: 70,
            failedCalls: 30
        );

        $config = [
            'minimum_number_of_calls' => 10,
            'failure_rate_threshold' => 50,
        ];

        $this->assertFalse($this->strategy->shouldOpen($metrics, $config));
    }

    public function testShouldCloseWithInsufficientCalls(): void
    {
        $metrics = new MetricsSnapshot(
            totalCalls: 3,
            successCalls: 3
        );

        $config = [
            'permitted_number_of_calls_in_half_open_state' => 5,
            'failure_rate_threshold' => 50,
        ];

        $this->assertFalse($this->strategy->shouldClose($metrics, $config));
    }

    public function testShouldCloseWithLowFailureRate(): void
    {
        $metrics = new MetricsSnapshot(
            totalCalls: 10,
            successCalls: 8,
            failedCalls: 2
        );

        $config = [
            'permitted_number_of_calls_in_half_open_state' => 5,
            'failure_rate_threshold' => 50,
        ];

        $this->assertTrue($this->strategy->shouldClose($metrics, $config));
    }

    public function testShouldCloseWithHighFailureRate(): void
    {
        $metrics = new MetricsSnapshot(
            totalCalls: 10,
            successCalls: 3,
            failedCalls: 7
        );

        $config = [
            'permitted_number_of_calls_in_half_open_state' => 5,
            'failure_rate_threshold' => 50,
        ];

        $this->assertFalse($this->strategy->shouldClose($metrics, $config));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->strategy = new FailureRateStrategy();
    }
}
