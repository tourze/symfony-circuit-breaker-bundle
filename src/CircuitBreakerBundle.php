<?php

namespace Tourze\Symfony\CircuitBreaker;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class CircuitBreakerBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
