<?php

namespace Tourze\Symfony\CircuitBreaker\Service;

use Psr\Log\LoggerInterface;
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
    /** @var callable|null */
    private $fallbackFactory;

    public function __construct(
        private readonly CircuitBreakerService $circuitBreakerService,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $servicePrefix = 'http_client_',
        ?callable $fallbackFactory = null,
    ) {
        $this->fallbackFactory = $fallbackFactory;
    }

    /**
     * 生成服务名称
     */
    private function generateServiceName(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        $host = false !== $host && null !== $host ? $host : 'unknown';

        return $this->servicePrefix . $host;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $serviceName = $this->generateServiceName($url);
        $startTime = microtime(true);

        // 记录请求开始日志
        $this->logger->info('HTTP请求开始', [
            'service' => $serviceName,
            'method' => $method,
            'url' => $url,
            'timestamp' => $startTime,
        ]);

        return $this->circuitBreakerService->execute(
            $serviceName,
            function () use ($method, $url, $options, $startTime) {
                try {
                    $response = $this->httpClient->request($method, $url, $options);
                    $duration = (microtime(true) - $startTime) * 1000;

                    // 记录成功响应日志
                    $this->logger->info('HTTP请求成功', [
                        'service' => $this->generateServiceName($url),
                        'method' => $method,
                        'url' => $url,
                        'status_code' => $response->getStatusCode(),
                        'duration_ms' => round($duration, 2),
                    ]);

                    return $response;
                } catch (\Throwable $e) {
                    $duration = (microtime(true) - $startTime) * 1000;

                    // 记录异常日志
                    $this->logger->error('HTTP请求异常', [
                        'service' => $this->generateServiceName($url),
                        'method' => $method,
                        'url' => $url,
                        'exception' => get_class($e),
                        'message' => $e->getMessage(),
                        'duration_ms' => round($duration, 2),
                    ]);

                    throw $e;
                }
            },
            null !== $this->fallbackFactory ? function () use ($method, $url, $options, $startTime) {
                $duration = (microtime(true) - $startTime) * 1000;

                // 记录降级处理日志
                $this->logger->warning('HTTP请求使用降级处理', [
                    'service' => $this->generateServiceName($url),
                    'method' => $method,
                    'url' => $url,
                    'duration_ms' => round($duration, 2),
                ]);

                return null !== $this->fallbackFactory ? ($this->fallbackFactory)($method, $url, $options) : null;
            } : null
        );
    }

    public function stream($responses, ?float $timeout = null): ResponseStreamInterface
    {
        // 对响应流不做熔断处理，直接透传
        return $this->httpClient->stream($responses, $timeout);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function withOptions(array $options): static
    {
        return new self(
            $this->circuitBreakerService,
            $this->httpClient->withOptions($options),
            $this->logger,
            $this->servicePrefix,
            $this->fallbackFactory,
        );
    }
}
