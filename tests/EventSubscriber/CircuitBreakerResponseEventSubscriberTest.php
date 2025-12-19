<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\EventSubscriber;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Tourze\PHPUnitSymfonyKernelTest\AbstractEventSubscriberTestCase;
use Tourze\Symfony\CircuitBreaker\EventSubscriber\CircuitBreakerResponseEventSubscriber;
use Tourze\Symfony\CircuitBreaker\Service\CircuitBreakerService;
use Tourze\Symfony\CircuitBreaker\Storage\MemoryStorage;

/**
 * @internal
 */
#[CoversClass(CircuitBreakerResponseEventSubscriber::class)]
#[RunTestsInSeparateProcesses]
final class CircuitBreakerResponseEventSubscriberTest extends AbstractEventSubscriberTestCase
{
    private CircuitBreakerService $circuitBreakerService;

    private CircuitBreakerResponseEventSubscriber $subscriber;

    private MemoryStorage $storage;

    protected function onSetUp(): void
    {
        // 使用真实服务而不是 mock
        $this->storage = new MemoryStorage();
        $container = self::getContainer();
        $container->set('Tourze\Symfony\CircuitBreaker\Storage\CircuitBreakerStorageInterface', $this->storage);

        $this->circuitBreakerService = self::getService(CircuitBreakerService::class);
        $this->subscriber = self::getService(CircuitBreakerResponseEventSubscriber::class);
    }

    /**
     * @return array<int, object|string>
     */
    private function createControllerArray(object $instance, string $method): array
    {
        return [$instance, $method];
    }

    public function testGetSubscribedEventsReturnsCorrectEvents(): void
    {
        $events = CircuitBreakerResponseEventSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey('kernel.controller', $events);
        $this->assertArrayHasKey('kernel.response', $events);
        $this->assertArrayHasKey('kernel.exception', $events);
    }

    public function testOnKernelResponseWithNormalResponseDoesNotModifyResponse(): void
    {
        $kernel = self::getContainer()->get('kernel');
        $request = new Request();
        $response = new Response('Test content');

        $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

        $this->subscriber->onKernelResponse($event);

        $this->assertEquals('Test content', $response->getContent());
    }

    public function testOnKernelControllerWithoutCircuitBreakerAttribute(): void
    {
        $kernel = self::getContainer()->get('kernel');
        $request = new Request();

        // 创建一个没有CircuitBreaker属性的控制器
        $testController = new TestControllerWithoutAttribute();
        $controller = $this->createControllerArray($testController, 'action');
        $event = new ControllerEvent($kernel, $controller, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->subscriber->onKernelController($event);

        // 请求属性中不应该有熔断器名称
        $this->assertNull($request->attributes->get('_circuit_breaker_name'));
    }

    public function testOnKernelControllerWithCircuitBreakerAttribute(): void
    {
        $kernel = self::getContainer()->get('kernel');
        $request = new Request();

        // 创建一个有CircuitBreaker属性的控制器
        $testController = new TestControllerWithAttribute();
        $controller = $this->createControllerArray($testController, 'actionWithCircuitBreaker');
        $event = new ControllerEvent($kernel, $controller, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->subscriber->onKernelController($event);

        // 请求属性中应该有熔断器名称
        $this->assertEquals('test-circuit', $request->attributes->get('_circuit_breaker_name'));
    }

    public function testOnKernelControllerWithNonArrayController(): void
    {
        $kernel = self::getContainer()->get('kernel');
        $request = new Request();

        // 使用非数组形式的控制器（如闭包）
        $controller = function () {
            return new Response();
        };
        $event = new ControllerEvent($kernel, $controller, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->subscriber->onKernelController($event);

        // 请求属性中不应该有熔断器名称
        $this->assertNull($request->attributes->get('_circuit_breaker_name'));
    }

    public function testOnKernelResponseWithSuccessStatus(): void
    {
        $kernel = self::getContainer()->get('kernel');
        $request = new Request();
        $request->attributes->set('_circuit_breaker_name', 'test-circuit');
        $response = new Response('Success', 200);

        $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

        // 记录初始指标
        $initialMetrics = $this->storage->getMetricsSnapshot('test-circuit', 100);
        $initialSuccessCalls = $initialMetrics->getSuccessCalls();

        $this->subscriber->onKernelResponse($event);

        // 验证成功调用被记录
        $metrics = $this->storage->getMetricsSnapshot('test-circuit', 100);
        $this->assertEquals($initialSuccessCalls + 1, $metrics->getSuccessCalls());
    }

    public function testOnKernelResponseWithErrorStatus(): void
    {
        $kernel = self::getContainer()->get('kernel');
        $request = new Request();
        $request->attributes->set('_circuit_breaker_name', 'test-circuit-error');
        $response = new Response('Error', 500);

        $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

        // 记录初始指标
        $initialMetrics = $this->storage->getMetricsSnapshot('test-circuit-error', 100);
        $initialFailedCalls = $initialMetrics->getFailedCalls();

        $this->subscriber->onKernelResponse($event);

        // 验证失败调用被记录
        $metrics = $this->storage->getMetricsSnapshot('test-circuit-error', 100);
        $this->assertEquals($initialFailedCalls + 1, $metrics->getFailedCalls());
    }

    public function testOnKernelExceptionRecordsFailure(): void
    {
        $kernel = self::getContainer()->get('kernel');
        $request = new Request();
        $request->attributes->set('_circuit_breaker_name', 'test-circuit-exception');
        $exception = new \RuntimeException('Test exception');

        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

        // 记录初始指标
        $initialMetrics = $this->storage->getMetricsSnapshot('test-circuit-exception', 100);
        $initialFailedCalls = $initialMetrics->getFailedCalls();

        $this->subscriber->onKernelException($event);

        // 验证失败调用被记录
        $metrics = $this->storage->getMetricsSnapshot('test-circuit-exception', 100);
        $this->assertEquals($initialFailedCalls + 1, $metrics->getFailedCalls());
    }

    public function testOnKernelExceptionWithoutCircuitName(): void
    {
        $kernel = self::getContainer()->get('kernel');
        $request = new Request();
        $exception = new \RuntimeException('Test exception');

        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

        // 记录初始指标 - 使用一个不应被记录的熔断器名称
        $initialMetrics = $this->storage->getMetricsSnapshot('unrelated-circuit', 100);
        $initialTotalCalls = $initialMetrics->getTotalCalls();

        $this->subscriber->onKernelException($event);

        // 验证没有调用被记录（因为没有熔断器名称）
        $metrics = $this->storage->getMetricsSnapshot('unrelated-circuit', 100);
        $this->assertEquals($initialTotalCalls, $metrics->getTotalCalls());
    }
}
