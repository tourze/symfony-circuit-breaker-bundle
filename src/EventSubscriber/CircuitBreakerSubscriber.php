<?php

namespace Tourze\Symfony\CircuitBreaker\EventSubscriber;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
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
class CircuitBreakerSubscriber implements EventSubscriberInterface
{
    /**
     * @param CircuitBreakerService $circuitBreakerService 熔断器服务
     * @param LoggerInterface $logger 日志记录器
     */
    public function __construct(
        private readonly CircuitBreakerService $circuitBreakerService,
        private readonly LoggerInterface $logger = new NullLogger()
    )
    {
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
     * @throws ReflectionException
     */
    public function onKernelController(ControllerEvent $event): void
    {
        $controller = $event->getController();

        // 处理控制器作为数组的情况 [ControllerClass, 'methodName']
        if (!is_array($controller)) {
            return;
        }

        [$controllerInstance, $methodName] = $controller;

        // 使用反射获取控制器方法
        $reflectionClass = new ReflectionClass($controllerInstance);
        if (!$reflectionClass->hasMethod($methodName)) {
            return;
        }

        $reflectionMethod = $reflectionClass->getMethod($methodName);

        // 查找CircuitBreaker注解
        $circuitBreakerAttribute = $this->getCircuitBreakerAttribute($reflectionMethod);
        if ($circuitBreakerAttribute === null) {
            return;
        }

        // 检查熔断器是否允许请求
        $circuitName = $circuitBreakerAttribute->name;
        if (!$this->circuitBreakerService->isAllowed($circuitName)) {
            // 如果配置了降级方法，则替换控制器为降级方法
            if ($circuitBreakerAttribute->fallbackMethod !== null) {
                $fallbackMethod = $circuitBreakerAttribute->fallbackMethod;

                if ($reflectionClass->hasMethod($fallbackMethod)) {
                    $this->logger->info('熔断器触发降级: {circuit}, 调用 {method}', [
                        'circuit' => $circuitName,
                        'method' => $fallbackMethod,
                    ]);

                    $event->setController([$controllerInstance, $fallbackMethod]);
                    return;
                }
            }

            // 否则抛出服务不可用异常
            $this->logger->warning('熔断器拒绝请求，无降级方法: {circuit}', [
                'circuit' => $circuitName,
            ]);

            throw new CircuitOpenException($circuitName);
        }
    }

    /**
     * 获取方法上的CircuitBreaker注解
     */
    private function getCircuitBreakerAttribute(ReflectionMethod $method): ?CircuitBreaker
    {
        $attributes = $method->getAttributes(CircuitBreaker::class);
        if (empty($attributes)) {
            return null;
        }

        return $attributes[0]->newInstance();
    }
}
