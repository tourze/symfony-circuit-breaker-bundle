<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Enum;

use PHPUnit\Framework\Attributes\CoversClass;
use Tourze\PHPUnitEnum\AbstractEnumTestCase;
use Tourze\Symfony\CircuitBreaker\Enum\CircuitState;

/**
 * @internal
 */
#[CoversClass(CircuitState::class)]
final class CircuitStateTest extends AbstractEnumTestCase
{
    public function testAllStatesExist(): void
    {
        $states = [
            CircuitState::CLOSED,
            CircuitState::OPEN,
            CircuitState::HALF_OPEN,
        ];

        $this->assertCount(3, $states);

        // 验证每个状态都有正确的值
        $this->assertEquals('closed', CircuitState::CLOSED->value);
        $this->assertEquals('open', CircuitState::OPEN->value);
        $this->assertEquals('half_open', CircuitState::HALF_OPEN->value);
    }

    public function testStateCanBeConvertedToString(): void
    {
        $this->assertEquals('closed', (string) CircuitState::CLOSED->value);
        $this->assertEquals('open', (string) CircuitState::OPEN->value);
        $this->assertEquals('half_open', (string) CircuitState::HALF_OPEN->value);
    }

    public function testLabelReturnsCorrectLabels(): void
    {
        $this->assertEquals('关闭', CircuitState::CLOSED->getLabel());
        $this->assertEquals('开启', CircuitState::OPEN->getLabel());
        $this->assertEquals('半开', CircuitState::HALF_OPEN->getLabel());
    }

    public function testGetLabelReturnsCorrectLabels(): void
    {
        $this->assertEquals('关闭', CircuitState::CLOSED->getLabel());
        $this->assertEquals('开启', CircuitState::OPEN->getLabel());
        $this->assertEquals('半开', CircuitState::HALF_OPEN->getLabel());
    }

    public function testToSelectItemReturnsCorrectFormat(): void
    {
        $closedItem = CircuitState::CLOSED->toSelectItem();
        $this->assertEquals('closed', $closedItem['value']);
        $this->assertEquals('关闭', $closedItem['label']);

        $openItem = CircuitState::OPEN->toSelectItem();
        $this->assertEquals('open', $openItem['value']);
        $this->assertEquals('开启', $openItem['label']);

        $halfOpenItem = CircuitState::HALF_OPEN->toSelectItem();
        $this->assertEquals('half_open', $halfOpenItem['value']);
        $this->assertEquals('半开', $halfOpenItem['label']);
    }

    public function testToArrayReturnsCorrectFormat(): void
    {
        $closedArray = CircuitState::CLOSED->toArray();
        $this->assertEquals('closed', $closedArray['value']);
        $this->assertEquals('关闭', $closedArray['label']);

        $openArray = CircuitState::OPEN->toArray();
        $this->assertEquals('open', $openArray['value']);
        $this->assertEquals('开启', $openArray['label']);

        $halfOpenArray = CircuitState::HALF_OPEN->toArray();
        $this->assertEquals('half_open', $halfOpenArray['value']);
        $this->assertEquals('半开', $halfOpenArray['label']);
    }
}
