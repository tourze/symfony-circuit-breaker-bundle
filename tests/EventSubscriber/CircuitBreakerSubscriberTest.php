<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\EventSubscriber;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Tourze\Symfony\CircuitBreaker\Attribute\CircuitBreaker;
use Tourze\Symfony\CircuitBreaker\EventSubscriber\CircuitBreakerSubscriber;
use Tourze\Symfony\CircuitBreaker\Exception\CircuitOpenException;
use Tourze\Symfony\CircuitBreaker\Service\CircuitBreakerService;

/**
 * 使用示例控制器进行测试
 */
class TestController
{
    #[CircuitBreaker(name: 'test.circuit')]
    public function circuitProtectedAction()
    {
        return 'protected result';
    }
    
    #[CircuitBreaker(name: 'test.circuit.with.fallback', fallbackMethod: 'fallbackAction')]
    public function actionWithFallback()
    {
        return 'original result';
    }
    
    public function fallbackAction()
    {
        return 'fallback result';
    }
    
    public function nonProtectedAction()
    {
        return 'non protected result';
    }
}

class CircuitBreakerSubscriberTest extends TestCase
{
    private CircuitBreakerService $circuitBreakerService;
    private CircuitBreakerSubscriber $subscriber;
    
    protected function setUp(): void
    {
        $this->circuitBreakerService = $this->createMock(CircuitBreakerService::class);
        $this->subscriber = new CircuitBreakerSubscriber($this->circuitBreakerService, new NullLogger());
    }
    
    /**
     * 创建控制器数组，避免PHPStan数组方法调用检查
     */
    private function createControllerArray(object $instance, string $method): array
    {
        return [$instance, $method];
    }
    
    public function testGetSubscribedEvents_returnsCorrectMapping()
    {
        $events = CircuitBreakerSubscriber::getSubscribedEvents();
        $this->assertArrayHasKey('kernel.controller', $events);
        $this->assertEquals(['onKernelController', 10], $events['kernel.controller']);
    }
    
    public function testOnKernelController_withNonArrayController_doesNothing()
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = $this->createMock(\Symfony\Component\HttpFoundation\Request::class);
        $controller = function() {};
        
        $event = new ControllerEvent($kernel, $controller, $request, HttpKernelInterface::MAIN_REQUEST);
        
        $this->circuitBreakerService->expects($this->never())
            ->method('isAllowed');
            
        $this->subscriber->onKernelController($event);
        
        // 确保控制器没有被修改
        $this->assertSame($controller, $event->getController());
    }
    
    public function testOnKernelController_withNonProtectedMethod_doesNothing()
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = $this->createMock(\Symfony\Component\HttpFoundation\Request::class);
        $controllerInstance = new TestController();
        $controller = function() use ($controllerInstance) {
            return $controllerInstance->nonProtectedAction();
        };
        
        $event = new ControllerEvent($kernel, $controller, $request, HttpKernelInterface::MAIN_REQUEST);
        
        $this->circuitBreakerService->expects($this->never())
            ->method('isAllowed');
            
        $this->subscriber->onKernelController($event);
        
        // 确保控制器没有被修改
        $this->assertSame($controller, $event->getController());
    }
    
    public function testOnKernelController_withProtectedMethodAndClosedCircuit_allowsExecution()
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = $this->createMock(\Symfony\Component\HttpFoundation\Request::class);
        $controllerInstance = new TestController();
        $controller = $this->createControllerArray($controllerInstance, 'circuitProtectedAction');
        
        $event = new ControllerEvent($kernel, $controller, $request, HttpKernelInterface::MAIN_REQUEST);
        
        $this->circuitBreakerService->expects($this->once())
            ->method('isAllowed')
            ->with('test.circuit')
            ->willReturn(true);
            
        $this->subscriber->onKernelController($event);
        
        // 确保控制器没有被修改
        $this->assertSame($controller, $event->getController());
    }
    
    public function testOnKernelController_withProtectedMethodAndOpenCircuitWithFallback_switchesToFallback()
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = $this->createMock(\Symfony\Component\HttpFoundation\Request::class);
        $controllerInstance = new TestController();
        $controller = $this->createControllerArray($controllerInstance, 'actionWithFallback');
        
        $event = new ControllerEvent($kernel, $controller, $request, HttpKernelInterface::MAIN_REQUEST);
        
        $this->circuitBreakerService->expects($this->once())
            ->method('isAllowed')
            ->with('test.circuit.with.fallback')
            ->willReturn(false);
            
        $this->subscriber->onKernelController($event);
        
        // 确保控制器已被修改为降级方法
        $fallbackController = $event->getController();
        $this->assertSame($controllerInstance, $fallbackController[0]);
        $this->assertEquals('fallbackAction', $fallbackController[1]);
    }
    
    public function testOnKernelController_withProtectedMethodAndOpenCircuitWithoutFallback_throwsException()
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = $this->createMock(\Symfony\Component\HttpFoundation\Request::class);
        $controllerInstance = new TestController();
        $controller = $this->createControllerArray($controllerInstance, 'circuitProtectedAction');
        
        $event = new ControllerEvent($kernel, $controller, $request, HttpKernelInterface::MAIN_REQUEST);
        
        $this->circuitBreakerService->expects($this->once())
            ->method('isAllowed')
            ->with('test.circuit')
            ->willReturn(false);
            
        $this->expectException(CircuitOpenException::class);
            
        $this->subscriber->onKernelController($event);
    }
} 