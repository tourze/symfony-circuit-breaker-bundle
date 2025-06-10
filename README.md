# Symfony Circuit Breaker Bundle

[![Latest Version](https://img.shields.io/packagist/v/tourze/symfony-circuit-breaker-bundle.svg?style=flat-square)](https://packagist.org/packages/tourze/symfony-circuit-breaker-bundle)

Symfony熔断器Bundle，基于 [ackintosh/ganesha](https://packagist.org/packages/ackintosh/ganesha) 实现，为Symfony应用提供熔断器功能，帮助您构建更健壮的应用。

## 功能特性

- 支持多种熔断器策略（失败率、超时等）
- 支持多种存储适配器（APCu、Redis、Memcached）
- 提供简单易用的注解（Attribute）方式使用熔断器
- 集成Symfony HTTP客户端熔断支持
- 自定义服务降级处理
- 完全可配置的熔断器行为
- 命令行工具查看和管理熔断器状态

## 安装

通过Composer安装：

```bash
composer require tourze/symfony-circuit-breaker-bundle
```

在Symfony项目中注册Bundle：

```php
// config/bundles.php
return [
    // ...
    Tourze\Symfony\CircuitBreaker\CircuitBreakerBundle::class => ['all' => true],
];
```

## 快速开始

### 使用注解（Attribute）方式

在控制器方法上使用熔断器注解：

```php
<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Tourze\Symfony\CircuitBreaker\Attribute\CircuitBreaker;

class ApiController extends AbstractController
{
    #[Route('/api/users', name: 'api_users')]
    #[CircuitBreaker(service: 'api.users', fallbackMethod: 'getUsersFallback')]
    public function getUsers(): Response
    {
        // 可能会失败的操作...
        // 例如：调用外部API
        
        return $this->json([
            'users' => [/* ... */],
        ]);
    }
    
    public function getUsersFallback(): Response
    {
        // 降级处理，当熔断器打开时会调用此方法
        return $this->json([
            'users' => [], 
            'message' => '服务暂时不可用，请稍后再试'
        ], 503);
    }
}
```

### 直接使用服务

通过依赖注入使用熔断器服务：

```php
<?php

namespace App\Service;

use Tourze\Symfony\CircuitBreaker\Service\CircuitBreakerService;

class ExternalApiService
{
    private CircuitBreakerService $circuitBreaker;
    
    public function __construct(CircuitBreakerService $circuitBreaker)
    {
        $this->circuitBreaker = $circuitBreaker;
    }
    
    public function callExternalApi(): array
    {
        return $this->circuitBreaker->execute(
            'external.api',  // 服务标识
            function() {
                // 实际的API调用代码
                $result = /* ... */;
                
                return $result;
            },
            function() {
                // 降级处理，当熔断器打开时执行
                return [
                    'error' => '服务暂时不可用',
                    'fallback_data' => true,
                ];
            }
        );
    }
}
```

### 使用带熔断功能的HTTP客户端

```php
<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class ApiClient
{
    private HttpClientInterface $httpClient;
    
    public function __construct(HttpClientInterface $circuitBreakerHttpClient)
    {
        // 自动注入 circuit_breaker.http_client 服务
        $this->httpClient = $circuitBreakerHttpClient;
    }
    
    public function fetchData(): array
    {
        try {
            $response = $this->httpClient->request('GET', 'https://api.example.com/data');
            
            return $response->toArray();
        } catch (\Exception $e) {
            // 处理异常...
            return [];
        }
    }
}
```

如果需要手动获取带熔断功能的HTTP客户端：

```php
// 在服务容器中获取
$client = $container->get('circuit_breaker.http_client');

// 或在控制器中
$client = $this->get('circuit_breaker.http_client');
```

## 命令行工具

查看熔断器配置信息：

```bash
php bin/console circuit-breaker:status --config
```

查看特定服务的熔断状态：

```bash
php bin/console circuit-breaker:status api.users
```

重置特定服务的熔断状态：

```bash
php bin/console circuit-breaker:status api.users --reset
```

强制打开熔断器（测试降级功能）：

```bash
php bin/console circuit-breaker:status api.users --force-open
```

强制关闭熔断器：

```bash
php bin/console circuit-breaker:status api.users --force-close
```

## 高级用法

### 手动管理熔断状态

你可以通过依赖注入获取熔断器服务，并手动管理熔断状态：

```php
use Tourze\Symfony\CircuitBreaker\Service\CircuitBreakerService;

class YourService
{
    private CircuitBreakerService $circuitBreaker;
    
    public function __construct(CircuitBreakerService $circuitBreaker)
    {
        $this->circuitBreaker = $circuitBreaker;
    }
    
    public function someMethod()
    {
        // 检查服务是否可用
        if (!$this->circuitBreaker->isAvailable('your.service')) {
            // 熔断器打开，执行降级逻辑
            return $this->fallback();
        }
        
        try {
            // 执行可能失败的操作
            $result = $this->doSomething();
            
            // 标记成功
            $this->circuitBreaker->markSuccess('your.service');
            
            return $result;
        } catch (\Exception $e) {
            // 标记失败
            $this->circuitBreaker->markFailure('your.service');
            
            // 重新抛出或处理异常
            throw $e;
        }
    }
}
```

### 自定义HTTP客户端降级处理

你可以通过自定义的HTTP客户端降级工厂来处理熔断时的HTTP请求：

```php
// config/services.yaml
services:
    App\Service\HttpClientFallbackFactory:
        arguments: []
        
    circuit_breaker.http_client:
        class: Tourze\Symfony\CircuitBreaker\Service\CircuitBreakerHttpClient
        arguments:
            $circuitBreakerService: '@Tourze\Symfony\CircuitBreaker\Service\CircuitBreakerService'
            $httpClient: '@http_client'
            $servicePrefix: 'http_client_'
            $fallbackFactory: '@App\Service\HttpClientFallbackFactory'
```

然后创建你的降级工厂：

```php
<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Component\HttpClient\Response\MockResponse;

class HttpClientFallbackFactory
{
    public function __invoke(string $method, string $url, array $options): ResponseInterface
    {
        // 根据不同的URL返回不同的降级响应
        if (strpos($url, '/users') !== false) {
            return new MockResponse(json_encode(['users' => []]), [
                'http_code' => 200,
            ]);
        }
        
        // 默认降级响应
        return new MockResponse(json_encode([
            'error' => '服务暂时不可用',
            'fallback' => true,
        ]), [
            'http_code' => 503,
        ]);
    }
}
```

## 参考资料

- [Ganesha - PHP实现的熔断器库](https://github.com/ackintosh/ganesha)
- [熔断器模式](https://martinfowler.com/bliki/CircuitBreaker.html)
- [微服务弹性设计模式：熔断器](https://docs.microsoft.com/en-us/azure/architecture/patterns/circuit-breaker)

## 许可证

本包基于 MIT 许可证。详情请参见 [LICENSE](LICENSE) 文件。
