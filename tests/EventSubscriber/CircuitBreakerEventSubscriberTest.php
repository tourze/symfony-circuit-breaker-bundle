<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\EventSubscriber;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Tourze\PHPUnitSymfonyKernelTest\AbstractEventSubscriberTestCase;
use Tourze\Symfony\CircuitBreaker\EventSubscriber\CircuitBreakerEventSubscriber;
use Tourze\Symfony\CircuitBreaker\Exception\CircuitOpenException;
use Tourze\Symfony\CircuitBreaker\Service\CircuitBreakerService;

/**
 * @internal
 */
#[CoversClass(CircuitBreakerEventSubscriber::class)]
#[RunTestsInSeparateProcesses]
final class CircuitBreakerEventSubscriberTest extends AbstractEventSubscriberTestCase
{
    private CircuitBreakerService $circuitBreakerService;

    private CircuitBreakerEventSubscriber $subscriber;

    protected function onSetUp(): void
    {
        $this->circuitBreakerService = self::getService(CircuitBreakerService::class);
        $this->subscriber = self::getService(CircuitBreakerEventSubscriber::class);
    }

    /**
     * 创建控制器数组，避免PHPStan数组方法调用检查
     * @return array<int, object|string>
     */
    private function createControllerArray(object $instance, string $method): array
    {
        return [$instance, $method];
    }

    public function testGetSubscribedEventsReturnsCorrectMapping(): void
    {
        $events = CircuitBreakerEventSubscriber::getSubscribedEvents();
        $this->assertArrayHasKey('kernel.controller', $events);
        $this->assertEquals(['onKernelController', 10], $events['kernel.controller']);
    }

    public function testOnKernelControllerWithNonArrayControllerDoesNothing(): void
    {
        $kernel = self::getContainer()->get('kernel');
        $request = Request::create('/test');
        $controller = function (): void {};
        $event = new ControllerEvent($kernel, $controller, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->subscriber->onKernelController($event);

        // 确保控制器没有被修改
        $this->assertSame($controller, $event->getController());
    }

    public function testOnKernelControllerWithNonProtectedMethodDoesNothing(): void
    {
        $kernel = self::getContainer()->get('kernel');
        $request = Request::create('/test');
        $controllerInstance = new TestController();
        $controller = function () use ($controllerInstance) {
            return $controllerInstance->nonProtectedAction();
        };
        $event = new ControllerEvent($kernel, $controller, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->subscriber->onKernelController($event);

        // 确保控制器没有被修改
        $this->assertSame($controller, $event->getController());
    }

    public function testOnKernelControllerWithProtectedMethodAndClosedCircuitAllowsExecution(): void
    {
        $kernel = self::getContainer()->get('kernel');
        $request = Request::create('/test');
        $controllerInstance = new TestController();
        $controller = $this->createControllerArray($controllerInstance, 'circuitProtectedAction');
        $event = new ControllerEvent($kernel, $controller, $request, HttpKernelInterface::MAIN_REQUEST);

        // 确保熔断器是关闭状态
        $this->circuitBreakerService->forceClose('test.circuit');

        $this->subscriber->onKernelController($event);

        // 确保控制器没有被修改
        $this->assertSame($controller, $event->getController());
    }

    public function testOnKernelControllerWithProtectedMethodAndOpenCircuitWithFallbackSwitchesToFallback(): void
    {
        $kernel = self::getContainer()->get('kernel');
        $request = Request::create('/test');
        $controllerInstance = new TestController();
        $controller = $this->createControllerArray($controllerInstance, 'actionWithFallback');
        $event = new ControllerEvent($kernel, $controller, $request, HttpKernelInterface::MAIN_REQUEST);

        // 强制打开熔断器
        $this->circuitBreakerService->forceOpen('test.circuit.with.fallback');

        $this->subscriber->onKernelController($event);

        // 确保控制器已被修改为降级方法
        $fallbackController = $event->getController();
        $this->assertIsArray($fallbackController);
        $this->assertSame($controllerInstance, $fallbackController[0]);
        $this->assertEquals('fallbackAction', $fallbackController[1]);
    }

    public function testOnKernelControllerWithProtectedMethodAndOpenCircuitWithoutFallbackThrowsException(): void
    {
        $kernel = self::getContainer()->get('kernel');
        $request = Request::create('/test');
        $controllerInstance = new TestController();
        $controller = $this->createControllerArray($controllerInstance, 'circuitProtectedAction');
        $event = new ControllerEvent($kernel, $controller, $request, HttpKernelInterface::MAIN_REQUEST);

        // 强制打开熔断器
        $this->circuitBreakerService->forceOpen('test.circuit');

        $this->expectException(CircuitOpenException::class);

        $this->subscriber->onKernelController($event);
    }
}
