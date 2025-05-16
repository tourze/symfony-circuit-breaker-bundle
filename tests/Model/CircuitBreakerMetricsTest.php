<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Model;

use PHPUnit\Framework\TestCase;
use Tourze\Symfony\CircuitBreaker\Model\CircuitBreakerMetrics;

class CircuitBreakerMetricsTest extends TestCase
{
    public function testInitialState_allCountersAreZero()
    {
        $metrics = new CircuitBreakerMetrics();
        
        $this->assertEquals(0, $metrics->getNumberOfCalls());
        $this->assertEquals(0, $metrics->getNumberOfSuccessfulCalls());
        $this->assertEquals(0, $metrics->getNumberOfFailedCalls());
        $this->assertEquals(0, $metrics->getNotPermittedCalls());
        $this->assertEquals(0.0, $metrics->getFailureRate());
    }
    
    public function testIncrementCalls_increasesTotalCalls()
    {
        $metrics = new CircuitBreakerMetrics();
        
        $metrics->incrementCalls();
        $this->assertEquals(1, $metrics->getNumberOfCalls());
        
        $metrics->incrementCalls();
        $this->assertEquals(2, $metrics->getNumberOfCalls());
    }
    
    public function testIncrementSuccessfulCalls_increasesSuccessCounter()
    {
        $metrics = new CircuitBreakerMetrics();
        
        $metrics->incrementSuccessfulCalls();
        $this->assertEquals(1, $metrics->getNumberOfSuccessfulCalls());
        
        $metrics->incrementSuccessfulCalls();
        $this->assertEquals(2, $metrics->getNumberOfSuccessfulCalls());
    }
    
    public function testIncrementFailedCalls_increasesFailureCounter()
    {
        $metrics = new CircuitBreakerMetrics();
        
        $metrics->incrementFailedCalls();
        $this->assertEquals(1, $metrics->getNumberOfFailedCalls());
        
        $metrics->incrementFailedCalls();
        $this->assertEquals(2, $metrics->getNumberOfFailedCalls());
    }
    
    public function testIncrementNotPermittedCalls_increasesNotPermittedCounter()
    {
        $metrics = new CircuitBreakerMetrics();
        
        $metrics->incrementNotPermittedCalls();
        $this->assertEquals(1, $metrics->getNotPermittedCalls());
        
        $metrics->incrementNotPermittedCalls();
        $this->assertEquals(2, $metrics->getNotPermittedCalls());
    }
    
    public function testReset_resetsAllCounters()
    {
        $metrics = new CircuitBreakerMetrics();
        
        // 增加各种计数
        $metrics->incrementCalls();
        $metrics->incrementSuccessfulCalls();
        $metrics->incrementFailedCalls();
        $metrics->incrementNotPermittedCalls();
        
        // 确认计数已增加
        $this->assertEquals(1, $metrics->getNumberOfCalls());
        $this->assertEquals(1, $metrics->getNumberOfSuccessfulCalls());
        $this->assertEquals(1, $metrics->getNumberOfFailedCalls());
        $this->assertEquals(1, $metrics->getNotPermittedCalls());
        
        // 重置并验证
        $metrics->reset();
        
        $this->assertEquals(0, $metrics->getNumberOfCalls());
        $this->assertEquals(0, $metrics->getNumberOfSuccessfulCalls());
        $this->assertEquals(0, $metrics->getNumberOfFailedCalls());
        $this->assertEquals(0, $metrics->getNotPermittedCalls());
    }
    
    public function testGetFailureRate_whenNoCallsReturnZero()
    {
        $metrics = new CircuitBreakerMetrics();
        $this->assertEquals(0.0, $metrics->getFailureRate());
    }
    
    public function testGetFailureRate_calculatesCorrectPercentage()
    {
        $metrics = new CircuitBreakerMetrics();
        
        // 10次调用，5次失败 = 50%失败率
        for ($i = 0; $i < 10; $i++) {
            $metrics->incrementCalls();
        }
        
        for ($i = 0; $i < 5; $i++) {
            $metrics->incrementFailedCalls();
        }
        
        $this->assertEquals(50.0, $metrics->getFailureRate());
        
        // 增加一次失败，变为 6/10 = 60%
        $metrics->incrementFailedCalls();
        $this->assertEquals(60.0, $metrics->getFailureRate());
    }
    
    public function testToArray_containsAllValues()
    {
        $metrics = new CircuitBreakerMetrics();
        
        // 设置一些值
        $metrics->incrementCalls();
        $metrics->incrementCalls();
        $metrics->incrementFailedCalls();
        $metrics->incrementNotPermittedCalls();
        
        $array = $metrics->toArray();
        
        $this->assertIsArray($array);
        $this->assertArrayHasKey('numberOfCalls', $array);
        $this->assertArrayHasKey('numberOfSuccessfulCalls', $array);
        $this->assertArrayHasKey('numberOfFailedCalls', $array);
        $this->assertArrayHasKey('notPermittedCalls', $array);
        $this->assertArrayHasKey('failureRate', $array);
        
        $this->assertEquals(2, $array['numberOfCalls']);
        $this->assertEquals(0, $array['numberOfSuccessfulCalls']);
        $this->assertEquals(1, $array['numberOfFailedCalls']);
        $this->assertEquals(1, $array['notPermittedCalls']);
        $this->assertEquals(50.0, $array['failureRate']);
    }
} 