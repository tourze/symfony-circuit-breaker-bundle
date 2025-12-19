<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tourze\PHPUnitSymfonyKernelTest\AbstractIntegrationTestCase;
use Tourze\Symfony\CircuitBreaker\Exception\CircuitOpenException;
use Tourze\Symfony\CircuitBreaker\Service\CircuitBreakerHttpClient;
use Tourze\Symfony\CircuitBreaker\Service\CircuitBreakerService;

/**
 * @internal
 */
#[CoversClass(CircuitBreakerHttpClient::class)]
#[RunTestsInSeparateProcesses]
final class CircuitBreakerHttpClientTest extends AbstractIntegrationTestCase
{
    private CircuitBreakerHttpClient $circuitBreakerHttpClient;

    private CircuitBreakerService $circuitBreakerService;

    protected function onSetUp(): void
    {
        $this->circuitBreakerHttpClient = self::getService(CircuitBreakerHttpClient::class);
        $this->circuitBreakerService = self::getService(CircuitBreakerService::class);
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

    public function testGenerateServiceNameWithUnknownHost(): void
    {
        $reflection = new \ReflectionClass($this->circuitBreakerHttpClient);
        $method = $reflection->getMethod('generateServiceName');
        $method->setAccessible(true);

        $serviceName = $method->invoke($this->circuitBreakerHttpClient, 'invalid-url');

        $this->assertIsString($serviceName);
        $this->assertStringContainsString('unknown', $serviceName);
    }

    public function testRequestWhenCircuitOpenThrowsException(): void
    {
        // 强制打开熔断器
        $this->circuitBreakerService->forceOpen('http_client_example.com');

        // 当熔断器打开且没有 fallback 时，应该抛出异常
        $this->expectException(CircuitOpenException::class);

        $this->circuitBreakerHttpClient->request('GET', 'https://example.com/api');
    }

    public function testWithOptionsCreatesNewInstance(): void
    {
        $options = ['timeout' => 30];

        $result = $this->circuitBreakerHttpClient->withOptions($options);

        $this->assertInstanceOf(CircuitBreakerHttpClient::class, $result);
        $this->assertNotSame($this->circuitBreakerHttpClient, $result);
    }

    public function testStreamDelegatesCorrectly(): void
    {
        // 创建一个空的响应数组来测试 stream 方法
        $responses = [];

        // stream 方法应该委托给底层的 httpClient
        $result = $this->circuitBreakerHttpClient->stream($responses);

        $this->assertInstanceOf(\Symfony\Contracts\HttpClient\ResponseStreamInterface::class, $result);
    }

    public function testServicePrefixInGeneratedName(): void
    {
        $reflection = new \ReflectionClass($this->circuitBreakerHttpClient);
        $method = $reflection->getMethod('generateServiceName');
        $method->setAccessible(true);

        $serviceName = $method->invoke($this->circuitBreakerHttpClient, 'https://api.example.com/v1/users');

        $this->assertStringStartsWith('http_client_', $serviceName);
        $this->assertStringContainsString('api.example.com', $serviceName);
    }

    public function testGenerateServiceNameWithDifferentUrls(): void
    {
        $reflection = new \ReflectionClass($this->circuitBreakerHttpClient);
        $method = $reflection->getMethod('generateServiceName');
        $method->setAccessible(true);

        $testCases = [
            'https://api.example.com/v1' => 'api.example.com',
            'http://localhost:8080/api' => 'localhost',
            'https://sub.domain.example.org/path' => 'sub.domain.example.org',
        ];

        foreach ($testCases as $url => $expectedHost) {
            $serviceName = $method->invoke($this->circuitBreakerHttpClient, $url);
            $this->assertStringContainsString($expectedHost, $serviceName, "Failed for URL: {$url}");
        }
    }
}
