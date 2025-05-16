<?php

namespace Tourze\Symfony\CircuitBreaker\Tests;

use PHPUnit\Framework\TestCase;
use Tourze\Symfony\CircuitBreaker\CircuitBreakerBundle;

class CircuitBreakerBundleTest extends TestCase
{
    public function testInstanceCanBeCreated()
    {
        $bundle = new CircuitBreakerBundle();
        $this->assertInstanceOf(CircuitBreakerBundle::class, $bundle);
    }
} 