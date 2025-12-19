<?php

namespace Tourze\Symfony\CircuitBreaker\EventSubscriber;

use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Tourze\Symfony\CircuitBreaker\Attribute\CircuitBreaker;
use Tourze\Symfony\CircuitBreaker\Exception\CircuitOpenException;
use Tourze\Symfony\CircuitBreaker\Service\CircuitBreakerService;

/**
 * 熔断器方法拦截器
 *
 * 用于拦截标记了CircuitBreaker注解的控制器方法
 */
#[WithMonologChannel(channel: 'circuit_breaker')]
final class CircuitBreakerEventSubscriber implements EventSubscriberInterface
{
    /**
     * @param CircuitBreakerService $circuitBreakerService 熔断器服务
     * @param LoggerInterface       $logger                日志记录器
     */
    public function __construct(
        private readonly CircuitBreakerService $circuitBreakerService,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => ['onKernelController', 10],
        ];
    }

    /**
     * 控制器方法调用前执行
     *
     * @throws \ReflectionException
     */
    public function onKernelController(ControllerEvent $event): void
    {
        $controller = $event->getController();

        if (!is_array($controller)) {
            return;
        }

        [$controllerInstance, $methodName] = $controller;
        $reflectionMethod = $this->getControllerMethod($controllerInstance, $methodName);

        if (null === $reflectionMethod) {
            return;
        }

        $circuitBreakerAttribute = $this->getCircuitBreakerAttribute($reflectionMethod);
        if (null === $circuitBreakerAttribute) {
            return;
        }

        $this->handleCircuitBreaker($event, $controllerInstance, $reflectionMethod, $circuitBreakerAttribute);
    }

    /**
     * 获取控制器方法反射对象
     */
    private function getControllerMethod(object $controllerInstance, string $methodName): ?\ReflectionMethod
    {
        $reflectionClass = new \ReflectionClass($controllerInstance);
        if (!$reflectionClass->hasMethod($methodName)) {
            return null;
        }

        return $reflectionClass->getMethod($methodName);
    }

    /**
     * 处理熔断器逻辑
     */
    private function handleCircuitBreaker(
        ControllerEvent $event,
        object $controllerInstance,
        \ReflectionMethod $reflectionMethod,
        CircuitBreaker $circuitBreakerAttribute,
    ): void {
        $circuitName = $circuitBreakerAttribute->name;

        if ($this->circuitBreakerService->isAllowed($circuitName)) {
            return;
        }

        if ($this->handleFallback($event, $controllerInstance, $reflectionMethod, $circuitBreakerAttribute)) {
            return;
        }

        $this->logger->warning('熔断器拒绝请求，无降级方法: {circuit}', [
            'circuit' => $circuitName,
        ]);

        throw new CircuitOpenException($circuitName);
    }

    /**
     * 处理降级方法
     */
    private function handleFallback(
        ControllerEvent $event,
        object $controllerInstance,
        \ReflectionMethod $reflectionMethod,
        CircuitBreaker $circuitBreakerAttribute,
    ): bool {
        if (null === $circuitBreakerAttribute->fallbackMethod) {
            return false;
        }

        $fallbackMethod = $circuitBreakerAttribute->fallbackMethod;
        $reflectionClass = $reflectionMethod->getDeclaringClass();

        if (!$reflectionClass->hasMethod($fallbackMethod)) {
            return false;
        }

        $this->logger->info('熔断器触发降级: {circuit}, 调用 {method}', [
            'circuit' => $circuitBreakerAttribute->name,
            'method' => $fallbackMethod,
        ]);

        /** @var callable $fallbackController */
        $fallbackController = [$controllerInstance, $fallbackMethod];
        $event->setController($fallbackController);

        return true;
    }

    /**
     * 获取方法上的CircuitBreaker注解
     */
    private function getCircuitBreakerAttribute(\ReflectionMethod $method): ?CircuitBreaker
    {
        $attributes = $method->getAttributes(CircuitBreaker::class);
        if ([] === $attributes) {
            return null;
        }

        return $attributes[0]->newInstance();
    }
}
