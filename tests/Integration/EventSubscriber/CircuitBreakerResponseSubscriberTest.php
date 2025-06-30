<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Integration\EventSubscriber;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Tourze\Symfony\CircuitBreaker\EventSubscriber\CircuitBreakerResponseSubscriber;

class CircuitBreakerResponseSubscriberTest extends TestCase
{
    private CircuitBreakerResponseSubscriber $subscriber;
    
    protected function setUp(): void
    {
        $circuitBreakerService = $this->createMock(\Tourze\Symfony\CircuitBreaker\Service\CircuitBreakerService::class);
        $this->subscriber = new CircuitBreakerResponseSubscriber($circuitBreakerService);
    }
    
    public function testGetSubscribedEvents_returnsCorrectEvents(): void
    {
        $events = CircuitBreakerResponseSubscriber::getSubscribedEvents();
        
        $this->assertArrayHasKey('kernel.response', $events);
    }
    
    public function testOnKernelResponse_withNormalResponse_doesNotModifyResponse(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = new Request();
        $response = new Response('Test content');
        
        $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
        
        $this->subscriber->onKernelResponse($event);
        
        $this->assertEquals('Test content', $response->getContent());
    }
}