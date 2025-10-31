<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\EventSubscriber;

use Symfony\Component\HttpFoundation\Response;
use Tourze\Symfony\CircuitBreaker\Attribute\CircuitBreaker;

class TestControllerWithAttribute
{
    #[CircuitBreaker(name: 'test-circuit')]
    public function actionWithCircuitBreaker(): Response
    {
        return new Response();
    }
}
