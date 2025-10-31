<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\EventSubscriber;

use Symfony\Component\HttpFoundation\Response;

class TestControllerWithoutAttribute
{
    public function action(): Response
    {
        return new Response();
    }
}
