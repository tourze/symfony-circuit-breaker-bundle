<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Tourze\Symfony\CircuitBreaker\Service\CircuitBreakerHttpClient;
use Tourze\Symfony\CircuitBreaker\Service\CircuitBreakerService;

/**
 * @internal
 */
#[CoversClass(CircuitBreakerHttpClient::class)]
final class CircuitBreakerHttpClientTest extends TestCase
{
    private CircuitBreakerService $circuitBreakerService;

    private HttpClientInterface $httpClient;

    private LoggerInterface $logger;

    private CircuitBreakerHttpClient $circuitBreakerHttpClient;

    protected function setUp(): void
    {
        parent::setUp();

        // 在测试中使用 createMock() 对具体类 CircuitBreakerService 进行 Mock
        // 理由1：CircuitBreakerService 是项目中的具体服务类，没有对应的接口
        // 理由2：测试重点是 CircuitBreakerHttpClient 的HTTP请求包装逻辑，而不是熔断器的具体实现
        // 理由3：Mock CircuitBreakerService 可以精确控制熔断器的行为，便于测试不同的熔断状态
        $this->circuitBreakerService = $this->createMock(CircuitBreakerService::class);
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->circuitBreakerHttpClient = new CircuitBreakerHttpClient(
            $this->circuitBreakerService,
            $this->httpClient,
            $this->logger
        );
    }

    public function testRequestWhenCircuitClosedMakesRequest(): void
    {
        // 创建mock响应
        $response = $this->createMock(ResponseInterface::class);

        // mock CircuitBreakerService.execute方法，模拟直接调用回调函数
        $this->circuitBreakerService->expects($this->once())
            ->method('execute')
            ->willReturnCallback(function (string $serviceName, callable $operation, ?callable $fallback = null) {
                // 直接调用操作回调函数，模拟熔断器允许请求通过
                return $operation();
            })
        ;

        // mock HttpClient.request方法
        $this->httpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'https://example.com/api', [])
            ->willReturn($response)
        ;

        // 执行测试
        $result = $this->circuitBreakerHttpClient->request('GET', 'https://example.com/api');

        // 验证返回的是mock响应
        $this->assertSame($response, $result);
    }

    public function testGenerateServiceNameExtractsHostFromUrl(): void
    {
        // 使用反射来访问private方法进行测试
        $reflection = new \ReflectionClass($this->circuitBreakerHttpClient);
        $method = $reflection->getMethod('generateServiceName');
        $method->setAccessible(true);

        $serviceName = $method->invoke($this->circuitBreakerHttpClient, 'https://example.com/api');

        $this->assertIsString($serviceName);
        $this->assertStringContainsString('example.com', $serviceName);
    }

    public function testStreamDelegatesToHttpClient(): void
    {
        $responses = [
            $this->createMock(ResponseInterface::class),
            $this->createMock(ResponseInterface::class),
        ];
        $timeout = 10.0;

        $responseStream = $this->createMock(ResponseStreamInterface::class);

        $this->httpClient
            ->expects($this->once())
            ->method('stream')
            ->with($responses, $timeout)
            ->willReturn($responseStream)
        ;

        $result = $this->circuitBreakerHttpClient->stream($responses, $timeout);

        $this->assertSame($responseStream, $result);
    }

    public function testStreamDelegatesToHttpClientWithoutTimeout(): void
    {
        $responses = [
            $this->createMock(ResponseInterface::class),
        ];

        $responseStream = $this->createMock(ResponseStreamInterface::class);

        $this->httpClient
            ->expects($this->once())
            ->method('stream')
            ->with($responses, null)
            ->willReturn($responseStream)
        ;

        $result = $this->circuitBreakerHttpClient->stream($responses);

        $this->assertSame($responseStream, $result);
    }

    public function testWithOptionsCreatesNewInstanceWithSameSettings(): void
    {
        $options = ['timeout' => 30];

        $newHttpClient = $this->createMock(HttpClientInterface::class);

        $this->httpClient
            ->expects($this->once())
            ->method('withOptions')
            ->with($options)
            ->willReturn($newHttpClient)
        ;

        $result = $this->circuitBreakerHttpClient->withOptions($options);

        $this->assertInstanceOf(CircuitBreakerHttpClient::class, $result);
        $this->assertNotSame($this->circuitBreakerHttpClient, $result);
    }

    public function testWithFallbackFactory(): void
    {
        $fallbackFactory = function ($method, $url, $options) {
            $response = $this->createMock(ResponseInterface::class);
            $response->method('getStatusCode')->willReturn(503);

            return $response;
        };

        $circuitBreakerHttpClient = new CircuitBreakerHttpClient(
            $this->circuitBreakerService,
            $this->httpClient,
            $this->logger,
            'test_',
            $fallbackFactory
        );

        // 模拟熔断器执行降级
        $this->circuitBreakerService->expects($this->once())
            ->method('execute')
            ->willReturnCallback(function (string $serviceName, callable $operation, ?callable $fallback) {
                // 调用降级函数
                if (null === $fallback) {
                    throw new \RuntimeException('Fallback should not be null');
                }

                return $fallback();
            })
        ;

        $result = $circuitBreakerHttpClient->request('GET', 'https://example.com/api');

        $this->assertInstanceOf(ResponseInterface::class, $result);
    }
}
