<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Model;

use PHPUnit\Framework\TestCase;
use Tourze\Symfony\CircuitBreaker\Model\MetricsSnapshot;

class MetricsSnapshotTest extends TestCase
{
    public function testInitialState(): void
    {
        $snapshot = new MetricsSnapshot();
        
        $this->assertEquals(0, $snapshot->getTotalCalls());
        $this->assertEquals(0, $snapshot->getSuccessCalls());
        $this->assertEquals(0, $snapshot->getFailedCalls());
        $this->assertEquals(0, $snapshot->getSlowCalls());
        $this->assertEquals(0, $snapshot->getNotPermittedCalls());
        $this->assertEquals(0.0, $snapshot->getAvgResponseTime());
        $this->assertEquals(0.0, $snapshot->getFailureRate());
        $this->assertEquals(100.0, $snapshot->getSuccessRate());
        $this->assertEquals(0.0, $snapshot->getSlowCallRate());
    }
    
    public function testWithValues(): void
    {
        $snapshot = new MetricsSnapshot(
            totalCalls: 100,
            successCalls: 70,
            failedCalls: 30,
            slowCalls: 10,
            notPermittedCalls: 5,
            avgResponseTime: 150.5,
            timestamp: 1234567890
        );
        
        $this->assertEquals(100, $snapshot->getTotalCalls());
        $this->assertEquals(70, $snapshot->getSuccessCalls());
        $this->assertEquals(30, $snapshot->getFailedCalls());
        $this->assertEquals(10, $snapshot->getSlowCalls());
        $this->assertEquals(5, $snapshot->getNotPermittedCalls());
        $this->assertEquals(150.5, $snapshot->getAvgResponseTime());
        $this->assertEquals(1234567890, $snapshot->getTimestamp());
    }
    
    public function testFailureRateCalculation(): void
    {
        $snapshot = new MetricsSnapshot(
            totalCalls: 100,
            successCalls: 60,
            failedCalls: 40
        );
        
        $this->assertEquals(40.0, $snapshot->getFailureRate());
        $this->assertEquals(60.0, $snapshot->getSuccessRate());
    }
    
    public function testSlowCallRateCalculation(): void
    {
        $snapshot = new MetricsSnapshot(
            totalCalls: 100,
            slowCalls: 25
        );
        
        $this->assertEquals(25.0, $snapshot->getSlowCallRate());
    }
    
    public function testToArray(): void
    {
        $snapshot = new MetricsSnapshot(
            totalCalls: 100,
            successCalls: 70,
            failedCalls: 30,
            slowCalls: 10,
            notPermittedCalls: 5,
            avgResponseTime: 150.5,
            timestamp: 1234567890
        );
        
        $array = $snapshot->toArray();
        
        $this->assertArrayHasKey('total_calls', $array);
        $this->assertArrayHasKey('success_calls', $array);
        $this->assertArrayHasKey('failed_calls', $array);
        $this->assertArrayHasKey('slow_calls', $array);
        $this->assertArrayHasKey('not_permitted_calls', $array);
        $this->assertArrayHasKey('failure_rate', $array);
        $this->assertArrayHasKey('success_rate', $array);
        $this->assertArrayHasKey('slow_call_rate', $array);
        $this->assertArrayHasKey('avg_response_time', $array);
        $this->assertArrayHasKey('timestamp', $array);
        
        $this->assertEquals(100, $array['total_calls']);
        $this->assertEquals(30.0, $array['failure_rate']);
        $this->assertEquals(70.0, $array['success_rate']);
        $this->assertEquals(10.0, $array['slow_call_rate']);
    }
    
    public function testFromArray(): void
    {
        $data = [
            'total_calls' => 100,
            'success_calls' => 70,
            'failed_calls' => 30,
            'slow_calls' => 10,
            'not_permitted_calls' => 5,
            'avg_response_time' => 150.5,
            'timestamp' => 1234567890
        ];
        
        $snapshot = MetricsSnapshot::fromArray($data);
        
        $this->assertEquals(100, $snapshot->getTotalCalls());
        $this->assertEquals(70, $snapshot->getSuccessCalls());
        $this->assertEquals(30, $snapshot->getFailedCalls());
        $this->assertEquals(10, $snapshot->getSlowCalls());
        $this->assertEquals(5, $snapshot->getNotPermittedCalls());
        $this->assertEquals(150.5, $snapshot->getAvgResponseTime());
        $this->assertEquals(1234567890, $snapshot->getTimestamp());
    }
}