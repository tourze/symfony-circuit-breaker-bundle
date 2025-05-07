<?php

namespace Tourze\Symfony\CircuitBreaker\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * 带熔断功能的HTTP客户端
 * 
 * 包装原始HTTP客户端，添加熔断功能
 */
class CircuitBreakerHttpClient implements HttpClientInterface
{
    private CircuitBreakerService $circuitBreakerService;
    private HttpClientInterface $httpClient;
    
    /**
     * 服务名称前缀
     */
    private string $servicePrefix;
    
    /**
     * 降级响应工厂（可选）
     * 
     * @var callable|null
     */
    private $fallbackFactory;

    public function __construct(
        CircuitBreakerService $circuitBreakerService,
        HttpClientInterface $httpClient,
        string $servicePrefix = 'http_client_',
        callable $fallbackFactory = null
    ) {
        $this->circuitBreakerService = $circuitBreakerService;
        $this->httpClient = $httpClient;
        $this->servicePrefix = $servicePrefix;
        $this->fallbackFactory = $fallbackFactory;
    }

    /**
     * 生成服务名称
     */
    private function generateServiceName(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?: 'unknown';
        return $this->servicePrefix . $host;
    }

    /**
     * {@inheritdoc}
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $serviceName = $this->generateServiceName($url);
        
        return $this->circuitBreakerService->execute(
            $serviceName,
            function () use ($method, $url, $options) {
                return $this->httpClient->request($method, $url, $options);
            },
            $this->fallbackFactory ? fn() => ($this->fallbackFactory)($method, $url, $options) : null
        );
    }

    /**
     * {@inheritdoc}
     */
    public function stream($responses, float $timeout = null): ResponseStreamInterface
    {
        // 对响应流不做熔断处理，直接透传
        return $this->httpClient->stream($responses, $timeout);
    }

    /**
     * {@inheritdoc}
     */
    public function withOptions(array $options): static
    {
        $new = clone $this;
        $new->httpClient = $this->httpClient->withOptions($options);
        
        return $new;
    }
}
