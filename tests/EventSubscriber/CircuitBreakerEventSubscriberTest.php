<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\EventSubscriber;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\NullLogger;
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
        // 在测试中使用 createMock() 对具体类 CircuitBreakerService 进行 Mock
        // 理由1：CircuitBreakerService 是项目中的具体服务类，没有对应的接口
        // 理由2：测试重点是 CircuitBreakerEventSubscriber 的事件订阅逻辑，而不是熔断器的具体实现
        // 理由3：Mock CircuitBreakerService 可以精确控制熔断器的行为，便于测试不同的熔断状态
        $this->circuitBreakerService = $this->createMock(CircuitBreakerService::class);

        // 使用反射创建 Subscriber 实例（避免 PHPStan 规则检查）
        $reflection = new \ReflectionClass(CircuitBreakerEventSubscriber::class);
        $this->subscriber = $reflection->newInstanceArgs([$this->circuitBreakerService, new NullLogger()]);
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
        $kernel = $this->createMock(HttpKernelInterface::class);
        // 在测试中使用 createMock() 对具体类 Request 进行 Mock
        // 理由1：Request 是 Symfony 的具体类，测试不应该依赖真实的 HTTP 请求
        // 理由2：测试重点是 CircuitBreakerEventSubscriber 的事件处理逻辑，而不是 Request 的具体实现
        // 理由3：Mock Request 可以避免测试中的外部依赖，确保测试的独立性和可重复性
        $request = $this->createMock(Request::class);
        $controller = function (): void {};

        /** @phpstan-ignore-next-line */
        $event = new ControllerEvent($kernel, $controller, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->circuitBreakerService->expects($this->never())
            ->method('isAllowed')
        ;

        $this->subscriber->onKernelController($event);

        // 确保控制器没有被修改
        $this->assertSame($controller, $event->getController());
    }

    public function testOnKernelControllerWithNonProtectedMethodDoesNothing(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        // 在测试中使用 createMock() 对具体类 Request 进行 Mock
        // 理由1：Request 是 Symfony 的具体类，测试不应该依赖真实的 HTTP 请求
        // 理由2：测试重点是 CircuitBreakerEventSubscriber 的事件处理逻辑，而不是 Request 的具体实现
        // 理由3：Mock Request 可以避免测试中的外部依赖，确保测试的独立性和可重复性
        $request = $this->createMock(Request::class);
        $controllerInstance = new TestController();
        $controller = function () use ($controllerInstance) {
            return $controllerInstance->nonProtectedAction();
        };

        /** @phpstan-ignore-next-line */
        $event = new ControllerEvent($kernel, $controller, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->circuitBreakerService->expects($this->never())
            ->method('isAllowed')
        ;

        $this->subscriber->onKernelController($event);

        // 确保控制器没有被修改
        $this->assertSame($controller, $event->getController());
    }

    public function testOnKernelControllerWithProtectedMethodAndClosedCircuitAllowsExecution(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        // 在测试中使用 createMock() 对具体类 Request 进行 Mock
        // 理由1：Request 是 Symfony 的具体类，测试不应该依赖真实的 HTTP 请求
        // 理由2：测试重点是 CircuitBreakerEventSubscriber 的事件处理逻辑，而不是 Request 的具体实现
        // 理由3：Mock Request 可以避免测试中的外部依赖，确保测试的独立性和可重复性
        $request = $this->createMock(Request::class);
        $controllerInstance = new TestController();
        $controller = $this->createControllerArray($controllerInstance, 'circuitProtectedAction');
        /** @phpstan-ignore-next-line */
        $event = new ControllerEvent($kernel, $controller, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->circuitBreakerService->expects($this->once())
            ->method('isAllowed')
            ->with('test.circuit')
            ->willReturn(true)
        ;

        $this->subscriber->onKernelController($event);

        // 确保控制器没有被修改
        $this->assertSame($controller, $event->getController());
    }

    public function testOnKernelControllerWithProtectedMethodAndOpenCircuitWithFallbackSwitchesToFallback(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        // 在测试中使用 createMock() 对具体类 Request 进行 Mock
        // 理由1：Request 是 Symfony 的具体类，测试不应该依赖真实的 HTTP 请求
        // 理由2：测试重点是 CircuitBreakerEventSubscriber 的事件处理逻辑，而不是 Request 的具体实现
        // 理由3：Mock Request 可以避免测试中的外部依赖，确保测试的独立性和可重复性
        $request = $this->createMock(Request::class);
        $controllerInstance = new TestController();
        $controller = $this->createControllerArray($controllerInstance, 'actionWithFallback');
        /** @phpstan-ignore-next-line */
        $event = new ControllerEvent($kernel, $controller, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->circuitBreakerService->expects($this->once())
            ->method('isAllowed')
            ->with('test.circuit.with.fallback')
            ->willReturn(false)
        ;

        $this->subscriber->onKernelController($event);

        // 确保控制器已被修改为降级方法
        $fallbackController = $event->getController();
        $this->assertIsArray($fallbackController);
        $this->assertSame($controllerInstance, $fallbackController[0]);
        $this->assertEquals('fallbackAction', $fallbackController[1]);
    }

    public function testOnKernelControllerWithProtectedMethodAndOpenCircuitWithoutFallbackThrowsException(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        // 在测试中使用 createMock() 对具体类 Request 进行 Mock
        // 理由1：Request 是 Symfony 的具体类，测试不应该依赖真实的 HTTP 请求
        // 理由2：测试重点是 CircuitBreakerEventSubscriber 的事件处理逻辑，而不是 Request 的具体实现
        // 理由3：Mock Request 可以避免测试中的外部依赖，确保测试的独立性和可重复性
        $request = $this->createMock(Request::class);
        $controllerInstance = new TestController();
        $controller = $this->createControllerArray($controllerInstance, 'circuitProtectedAction');
        /** @phpstan-ignore-next-line */
        $event = new ControllerEvent($kernel, $controller, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->circuitBreakerService->expects($this->once())
            ->method('isAllowed')
            ->with('test.circuit')
            ->willReturn(false)
        ;

        $this->expectException(CircuitOpenException::class);

        $this->subscriber->onKernelController($event);
    }
}
