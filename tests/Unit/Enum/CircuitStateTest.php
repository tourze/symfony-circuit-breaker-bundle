<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Unit\Enum;

use PHPUnit\Framework\TestCase;
use Tourze\Symfony\CircuitBreaker\Enum\CircuitState;

class CircuitStateTest extends TestCase
{
    public function testAllStatesExist(): void
    {
        $states = [
            CircuitState::CLOSED,
            CircuitState::OPEN,
            CircuitState::HALF_OPEN
        ];
        
        $this->assertCount(3, $states);
        
        // 验证每个状态都有正确的值
        $this->assertEquals('closed', CircuitState::CLOSED->value);
        $this->assertEquals('open', CircuitState::OPEN->value);
        $this->assertEquals('half_open', CircuitState::HALF_OPEN->value);
    }
    
    public function testStateCanBeConvertedToString(): void
    {
        $this->assertEquals('closed', (string)CircuitState::CLOSED->value);
        $this->assertEquals('open', (string)CircuitState::OPEN->value);
        $this->assertEquals('half_open', (string)CircuitState::HALF_OPEN->value);
    }
}