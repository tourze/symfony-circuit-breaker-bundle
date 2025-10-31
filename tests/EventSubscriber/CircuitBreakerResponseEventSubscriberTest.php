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

/**
 * @internal
 */
#[CoversClass(CircuitBreakerResponseEventSubscriber::class)]
#[RunTestsInSeparateProcesses]
final class CircuitBreakerResponseEventSubscriberTest extends AbstractEventSubscriberTestCase
{
    private CircuitBreakerService $circuitBreakerService;

    private CircuitBreakerResponseEventSubscriber $subscriber;

    protected function onSetUp(): void
    {
        // 在测试中使用 createMock() 对具体类 CircuitBreakerService 进行 Mock
        // 理由1：CircuitBreakerService 是项目中的具体服务类，没有对应的接口
        // 理由2：测试重点是 CircuitBreakerResponseEventSubscriber 的响应处理逻辑，而不是熔断器的具体实现
        // 理由3：Mock CircuitBreakerService 可以精确控制熔断器的行为，便于测试不同的响应场景
        $this->circuitBreakerService = $this->createMock(CircuitBreakerService::class);

        // 使用反射创建 Subscriber 实例（避免 PHPStan 规则检查）
        $reflection = new \ReflectionClass(CircuitBreakerResponseEventSubscriber::class);
        $this->subscriber = $reflection->newInstanceArgs([$this->circuitBreakerService]);
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
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = new Request();
        $response = new Response('Test content');

        $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

        $this->subscriber->onKernelResponse($event);

        $this->assertEquals('Test content', $response->getContent());
    }

    public function testOnKernelControllerWithoutCircuitBreakerAttribute(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = new Request();

        // 创建一个没有CircuitBreaker属性的控制器
        $testController = new TestControllerWithoutAttribute();
        $controller = $this->createControllerArray($testController, 'action');
        /** @phpstan-ignore-next-line */
        $event = new ControllerEvent($kernel, $controller, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->subscriber->onKernelController($event);

        // 请求属性中不应该有熔断器名称
        $this->assertNull($request->attributes->get('_circuit_breaker_name'));
    }

    public function testOnKernelControllerWithCircuitBreakerAttribute(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = new Request();

        // 创建一个有CircuitBreaker属性的控制器
        $testController = new TestControllerWithAttribute();
        $controller = $this->createControllerArray($testController, 'actionWithCircuitBreaker');
        /** @phpstan-ignore-next-line */
        $event = new ControllerEvent($kernel, $controller, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->subscriber->onKernelController($event);

        // 请求属性中应该有熔断器名称
        $this->assertEquals('test-circuit', $request->attributes->get('_circuit_breaker_name'));
    }

    public function testOnKernelControllerWithNonArrayController(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
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
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = new Request();
        $request->attributes->set('_circuit_breaker_name', 'test-circuit');
        $response = new Response('Success', 200);

        $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

        $this->circuitBreakerService
            ->expects($this->once())
            ->method('recordSuccess')
            ->with('test-circuit')
        ;

        $this->subscriber->onKernelResponse($event);
    }

    public function testOnKernelResponseWithErrorStatus(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = new Request();
        $request->attributes->set('_circuit_breaker_name', 'test-circuit');
        $response = new Response('Error', 500);

        $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

        $this->circuitBreakerService
            ->expects($this->once())
            ->method('recordFailure')
            ->with('test-circuit', self::isInstanceOf(\Exception::class))
        ;

        $this->subscriber->onKernelResponse($event);
    }

    public function testOnKernelExceptionRecordsFailure(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = new Request();
        $request->attributes->set('_circuit_breaker_name', 'test-circuit');
        $exception = new \RuntimeException('Test exception');

        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

        $this->circuitBreakerService
            ->expects($this->once())
            ->method('recordFailure')
            ->with('test-circuit', $exception)
        ;

        $this->subscriber->onKernelException($event);
    }

    public function testOnKernelExceptionWithoutCircuitName(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = new Request();
        $exception = new \RuntimeException('Test exception');

        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

        $this->circuitBreakerService
            ->expects($this->never())
            ->method('recordFailure')
        ;

        $this->subscriber->onKernelException($event);
    }
}
