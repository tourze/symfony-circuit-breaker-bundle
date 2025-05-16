<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Attribute;

use PHPUnit\Framework\TestCase;
use Tourze\Symfony\CircuitBreaker\Attribute\CircuitBreaker;

class CircuitBreakerTest extends TestCase
{
    public function testConstructor_withRequiredParamsOnly()
    {
        $circuitBreaker = new CircuitBreaker('api.service');
        
        $this->assertEquals('api.service', $circuitBreaker->name);
        $this->assertNull($circuitBreaker->fallbackMethod);
        $this->assertEquals(50, $circuitBreaker->failureRateThreshold);
        $this->assertEquals(10, $circuitBreaker->minimumNumberOfCalls);
        $this->assertEquals(100, $circuitBreaker->slidingWindowSize);
        $this->assertEquals(60, $circuitBreaker->waitDurationInOpenState);
        $this->assertEquals(10, $circuitBreaker->permittedNumberOfCallsInHalfOpenState);
        $this->assertTrue($circuitBreaker->automaticTransitionFromOpenToHalfOpenEnabled);
        $this->assertEmpty($circuitBreaker->recordExceptions);
        $this->assertEmpty($circuitBreaker->ignoreExceptions);
    }
    
    public function testConstructor_withAllParams()
    {
        $circuitBreaker = new CircuitBreaker(
            name: 'test.service',
            fallbackMethod: 'fallbackAction',
            failureRateThreshold: 75,
            minimumNumberOfCalls: 20,
            slidingWindowSize: 50,
            waitDurationInOpenState: 30,
            permittedNumberOfCallsInHalfOpenState: 5,
            automaticTransitionFromOpenToHalfOpenEnabled: false,
            recordExceptions: ['\RuntimeException', '\LogicException'],
            ignoreExceptions: ['\InvalidArgumentException']
        );
        
        $this->assertEquals('test.service', $circuitBreaker->name);
        $this->assertEquals('fallbackAction', $circuitBreaker->fallbackMethod);
        $this->assertEquals(75, $circuitBreaker->failureRateThreshold);
        $this->assertEquals(20, $circuitBreaker->minimumNumberOfCalls);
        $this->assertEquals(50, $circuitBreaker->slidingWindowSize);
        $this->assertEquals(30, $circuitBreaker->waitDurationInOpenState);
        $this->assertEquals(5, $circuitBreaker->permittedNumberOfCallsInHalfOpenState);
        $this->assertFalse($circuitBreaker->automaticTransitionFromOpenToHalfOpenEnabled);
        $this->assertEquals(['\RuntimeException', '\LogicException'], $circuitBreaker->recordExceptions);
        $this->assertEquals(['\InvalidArgumentException'], $circuitBreaker->ignoreExceptions);
    }
    
    public function testInstanceProperties_areReadonly()
    {
        $reflection = new \ReflectionProperty(CircuitBreaker::class, 'name');
        $this->assertTrue($reflection->isReadOnly());
        
        $reflection = new \ReflectionProperty(CircuitBreaker::class, 'failureRateThreshold');
        $this->assertTrue($reflection->isReadOnly());
    }
    
    public function testAttribute_isTargetedOnMethods()
    {
        $reflection = new \ReflectionClass(CircuitBreaker::class);
        $attributes = $reflection->getAttributes(\Attribute::class);
        
        $this->assertCount(1, $attributes);
        $attribute = $attributes[0]->newInstance();
        
        $this->assertEquals(\Attribute::TARGET_METHOD, $attribute->flags);
    }
} 