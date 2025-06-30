<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Integration\Service;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Tourze\Symfony\CircuitBreaker\Service\CircuitBreakerHttpClient;
use Tourze\Symfony\CircuitBreaker\Service\CircuitBreakerService;

class CircuitBreakerHttpClientTest extends TestCase
{
    private CircuitBreakerService $circuitBreakerService;
    private HttpClientInterface $httpClient;
    private CircuitBreakerHttpClient $circuitBreakerHttpClient;
    
    protected function setUp(): void
    {
        $this->circuitBreakerService = $this->createMock(CircuitBreakerService::class);
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->circuitBreakerHttpClient = new CircuitBreakerHttpClient(
            $this->circuitBreakerService,
            $this->httpClient
        );
    }
    
    public function testRequest_whenCircuitClosed_makesRequest(): void
    {
        // 创建mock响应
        $response = $this->createMock(ResponseInterface::class);
        
        // mock CircuitBreakerService.execute方法，模拟直接调用回调函数
        $this->circuitBreakerService->expects($this->once())
            ->method('execute')
            ->willReturnCallback(function ($serviceName, $operation, $fallback = null) {
                // 直接调用操作回调函数，模拟熔断器允许请求通过
                return $operation();
            });
        
        // mock HttpClient.request方法
        $this->httpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'https://example.com/api', [])
            ->willReturn($response);
        
        // 执行测试
        $result = $this->circuitBreakerHttpClient->request('GET', 'https://example.com/api');
        
        // 验证返回的是mock响应
        $this->assertSame($response, $result);
    }
    
    public function testGenerateServiceName_extractsHostFromUrl(): void
    {
        // 使用反射来访问private方法进行测试
        $reflection = new \ReflectionClass($this->circuitBreakerHttpClient);
        $method = $reflection->getMethod('generateServiceName');
        $method->setAccessible(true);
        
        $serviceName = $method->invoke($this->circuitBreakerHttpClient, 'https://example.com/api');
        
        $this->assertStringContainsString('example.com', $serviceName);
    }
}