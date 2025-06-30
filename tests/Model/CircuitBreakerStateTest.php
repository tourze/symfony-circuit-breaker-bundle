<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Model;

use PHPUnit\Framework\TestCase;
use Tourze\Symfony\CircuitBreaker\Enum\CircuitState;
use Tourze\Symfony\CircuitBreaker\Model\CircuitBreakerState;

class CircuitBreakerStateTest extends TestCase
{
    public function testConstructor_withDefaultValues()
    {
        $state = new CircuitBreakerState();
        
        $this->assertEquals(CircuitState::CLOSED, $state->getState());
        $this->assertGreaterThanOrEqual(time() - 1, $state->getTimestamp());
        $this->assertEquals(0, $state->getAttemptCount());
    }
    
    public function testConstructor_withCustomValues()
    {
        $timestamp = time() - 100;
        $state = new CircuitBreakerState(CircuitState::OPEN, $timestamp, 5);
        
        $this->assertEquals(CircuitState::OPEN, $state->getState());
        $this->assertEquals($timestamp, $state->getTimestamp());
        $this->assertEquals(5, $state->getAttemptCount());
    }
    
    public function testSetState_updatesStateAndTimestamp()
    {
        $state = new CircuitBreakerState();
        $beforeTimestamp = $state->getTimestamp();
        
        // 确保时间戳发生变化
        sleep(1);
        
        $state->setState(CircuitState::OPEN);
        
        $this->assertEquals(CircuitState::OPEN, $state->getState());
        $this->assertGreaterThan($beforeTimestamp, $state->getTimestamp());
    }
    
    public function testSetState_resetsAttemptCountWhenHalfOpen()
    {
        $state = new CircuitBreakerState(CircuitState::CLOSED, time(), 5);
        $this->assertEquals(5, $state->getAttemptCount());
        
        $state->setState(CircuitState::HALF_OPEN);
        
        $this->assertEquals(CircuitState::HALF_OPEN, $state->getState());
        $this->assertEquals(0, $state->getAttemptCount());
    }
    
    public function testIncrementAttemptCount_increasesCounter()
    {
        $state = new CircuitBreakerState();
        $this->assertEquals(0, $state->getAttemptCount());
        
        $state->incrementAttemptCount();
        $this->assertEquals(1, $state->getAttemptCount());
        
        $state->incrementAttemptCount();
        $this->assertEquals(2, $state->getAttemptCount());
    }
    
    public function testIsClosed_returnsCorrectState()
    {
        $closedState = new CircuitBreakerState(CircuitState::CLOSED);
        $openState = new CircuitBreakerState(CircuitState::OPEN);
        $halfOpenState = new CircuitBreakerState(CircuitState::HALF_OPEN);
        
        $this->assertTrue($closedState->isClosed());
        $this->assertFalse($openState->isClosed());
        $this->assertFalse($halfOpenState->isClosed());
    }
    
    public function testIsOpen_returnsCorrectState()
    {
        $closedState = new CircuitBreakerState(CircuitState::CLOSED);
        $openState = new CircuitBreakerState(CircuitState::OPEN);
        $halfOpenState = new CircuitBreakerState(CircuitState::HALF_OPEN);
        
        $this->assertFalse($closedState->isOpen());
        $this->assertTrue($openState->isOpen());
        $this->assertFalse($halfOpenState->isOpen());
    }
    
    public function testIsHalfOpen_returnsCorrectState()
    {
        $closedState = new CircuitBreakerState(CircuitState::CLOSED);
        $openState = new CircuitBreakerState(CircuitState::OPEN);
        $halfOpenState = new CircuitBreakerState(CircuitState::HALF_OPEN);
        
        $this->assertFalse($closedState->isHalfOpen());
        $this->assertFalse($openState->isHalfOpen());
        $this->assertTrue($halfOpenState->isHalfOpen());
    }
    
    public function testToArray_containsAllValues()
    {
        $timestamp = time();
        $state = new CircuitBreakerState(CircuitState::HALF_OPEN, $timestamp, 3);
        
        $array = $state->toArray();
        $this->assertArrayHasKey('state', $array);
        $this->assertArrayHasKey('timestamp', $array);
        $this->assertArrayHasKey('attemptCount', $array);
        
        $this->assertEquals(CircuitState::HALF_OPEN->value, $array['state']);
        $this->assertEquals($timestamp, $array['timestamp']);
        $this->assertEquals(3, $array['attemptCount']);
    }
    
    public function testFromArray_createsCorrectInstance()
    {
        $data = [
            'state' => CircuitState::OPEN->value,
            'timestamp' => 1234567890,
            'attemptCount' => 7
        ];
        
        $state = CircuitBreakerState::fromArray($data);
        
        $this->assertEquals(CircuitState::OPEN, $state->getState());
        $this->assertEquals(1234567890, $state->getTimestamp());
        $this->assertEquals(7, $state->getAttemptCount());
    }
    
    public function testFromArray_withDefaultValues()
    {
        $state = CircuitBreakerState::fromArray([]);
        
        $this->assertEquals(CircuitState::CLOSED, $state->getState());
        $this->assertGreaterThanOrEqual(time() - 1, $state->getTimestamp());
        $this->assertEquals(0, $state->getAttemptCount());
    }
    
    public function testCircuitStateGenOptions_returnsCorrectFormat()
    {
        $options = CircuitState::genOptions();
        
        $expectedOptions = [
            [
                'label' => '关闭',
                'text' => '关闭',
                'value' => 'closed',
                'name' => '关闭',
            ],
            [
                'label' => '开启',
                'text' => '开启',
                'value' => 'open',
                'name' => '开启',
            ],
            [
                'label' => '半开',
                'text' => '半开',
                'value' => 'half_open',
                'name' => '半开',
            ],
        ];
        
        $this->assertEquals($expectedOptions, $options);
    }
} 