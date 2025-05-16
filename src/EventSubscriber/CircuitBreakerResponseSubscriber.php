<?php

namespace Tourze\Symfony\CircuitBreaker\EventSubscriber;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Tourze\Symfony\CircuitBreaker\Attribute\CircuitBreaker;
use Tourze\Symfony\CircuitBreaker\Service\CircuitBreakerService;

/**
 * 请求结果收集器
 * 
 * 用于自动收集控制器方法的成功/失败信息
 */
class CircuitBreakerResponseSubscriber implements EventSubscriberInterface
{
    /**
     * 当前请求中的熔断器名称属性
     */
    private const REQUEST_ATTR_CIRCUIT = '_circuit_breaker_name';

    /**
     * @param CircuitBreakerService $circuitBreakerService 熔断器服务
     * @param LoggerInterface|null $logger 日志记录器
     */
    public function __construct(
        private readonly CircuitBreakerService $circuitBreakerService,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => ['onKernelController', 0],
            KernelEvents::RESPONSE => ['onKernelResponse', 0],
            KernelEvents::EXCEPTION => ['onKernelException', 0],
        ];
    }

    /**
     * 控制器调用前，记录当前请求使用的熔断器名称
     * 
     * @throws ReflectionException
     */
    public function onKernelController(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        $controller = $event->getController();

        // 只处理数组形式的控制器
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
        $attributes = $reflectionMethod->getAttributes(CircuitBreaker::class);
        if (empty($attributes)) {
            return;
        }

        // 获取熔断器名称并存储到请求属性中
        $circuitBreakerAttribute = $attributes[0]->newInstance();
        $request->attributes->set(self::REQUEST_ATTR_CIRCUIT, $circuitBreakerAttribute->name);
    }

    /**
     * 请求成功完成
     */
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $circuitName = $request->attributes->get(self::REQUEST_ATTR_CIRCUIT);

        if ($circuitName === null) {
            return;
        }

        $response = $event->getResponse();
        $statusCode = $response->getStatusCode();

        // 2xx 和 3xx 状态码视为成功，其他视为失败
        if ($statusCode >= 200 && $statusCode < 400) {
            $this->circuitBreakerService->recordSuccess($circuitName);
            
            $this->logger->debug('熔断器记录成功: {circuit}, 状态码: {status}', [
                'circuit' => $circuitName,
                'status' => $statusCode,
            ]);
        } else {
            $this->circuitBreakerService->recordFailure($circuitName, new \Exception("HTTP状态码错误: {$statusCode}"));
            
            $this->logger->debug('熔断器记录失败: {circuit}, 状态码: {status}', [
                'circuit' => $circuitName,
                'status' => $statusCode,
            ]);
        }
    }

    /**
     * 请求出现异常
     */
    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $circuitName = $request->attributes->get(self::REQUEST_ATTR_CIRCUIT);

        if ($circuitName === null) {
            return;
        }

        // 获取异常
        $throwable = $event->getThrowable();
        
        // 出现异常，标记失败
        $this->circuitBreakerService->recordFailure($circuitName, $throwable);
        
        $this->logger->debug('熔断器记录异常: {circuit}, 异常: {exception}', [
            'circuit' => $circuitName,
            'exception' => get_class($throwable),
            'message' => $throwable->getMessage(),
        ]);
    }
} 