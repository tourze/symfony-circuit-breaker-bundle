# Symfony Circuit Breaker Bundle

[![Latest Version](https://img.shields.io/packagist/v/tourze/symfony-circuit-breaker-bundle.svg?style=flat-square)](https://packagist.org/packages/tourze/symfony-circuit-breaker-bundle)

A Symfony bundle that provides circuit breaker functionality based on [ackintosh/ganesha](https://packagist.org/packages/ackintosh/ganesha), helping you build more resilient applications.

[English](README.en.md) | [中文](README.md)

## Features

- Multiple circuit breaker strategies (failure rate, timeout, etc.)
- Multiple storage adapters (APCu, Redis, Memcached)
- Easy-to-use PHP 8 attributes for circuit breaker implementation
- Integration with Symfony HTTP client
- Custom service degradation handling
- Fully configurable circuit breaker behavior
- Command-line tools for viewing and managing circuit breaker status

## Installation

Install via Composer:

```bash
composer require tourze/symfony-circuit-breaker-bundle
```

Register the bundle in your Symfony project:

```php
// config/bundles.php
return [
    // ...
    Tourze\Symfony\CircuitBreaker\CircuitBreakerBundle::class => ['all' => true],
];
```

## Quick Start

### Using Attributes

Use circuit breaker attributes on controller methods:

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
        // Potentially failing operation...
        // For example: calling an external API
        
        return $this->json([
            'users' => [/* ... */],
        ]);
    }
    
    public function getUsersFallback(): Response
    {
        // Fallback handling when circuit is open
        return $this->json([
            'users' => [], 
            'message' => 'Service temporarily unavailable, please try again later'
        ], 503);
    }
}
```

### Using the Service Directly

Use the circuit breaker service through dependency injection:

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
            'external.api',  // Service identifier
            function() {
                // Actual API call code
                $result = /* ... */;
                
                return $result;
            },
            function() {
                // Fallback handling when circuit is open
                return [
                    'error' => 'Service temporarily unavailable',
                    'fallback_data' => true,
                ];
            }
        );
    }
}
```

### Using the HTTP Client with Circuit Breaker

```php
<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class ApiClient
{
    private HttpClientInterface $httpClient;
    
    public function __construct(HttpClientInterface $circuitBreakerHttpClient)
    {
        // Auto-injected circuit_breaker.http_client service
        $this->httpClient = $circuitBreakerHttpClient;
    }
    
    public function fetchData(): array
    {
        try {
            $response = $this->httpClient->request('GET', 'https://api.example.com/data');
            
            return $response->toArray();
        } catch (\Throwable $e) {
            // Handle exceptions...
            return [];
        }
    }
}
```

If you need to manually get the HTTP client with circuit breaker functionality:

```php
// Get from service container
$client = $container->get('circuit_breaker.http_client');

// Or in controllers
$client = $this->get('circuit_breaker.http_client');
```

## Command Line Tools

View circuit breaker configuration:

```bash
php bin/console circuit-breaker:status --config
```

Check the status of a specific service:

```bash
php bin/console circuit-breaker:status api.users
```

Reset circuit breaker status for a specific service:

```bash
php bin/console circuit-breaker:status api.users --reset
```

Force open a circuit breaker (for testing fallback functionality):

```bash
php bin/console circuit-breaker:status api.users --force-open
```

Force close a circuit breaker:

```bash
php bin/console circuit-breaker:status api.users --force-close
```

## Advanced Usage

### Manually Managing Circuit Breaker State

You can get the circuit breaker service through dependency injection and manually manage the circuit breaker state:

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
        // Check if service is available
        if (!$this->circuitBreaker->isAvailable('your.service')) {
            // Circuit is open, execute fallback logic
            return $this->fallback();
        }
        
        try {
            // Execute potentially failing operation
            $result = $this->doSomething();
            
            // Mark as success
            $this->circuitBreaker->markSuccess('your.service');
            
            return $result;
        } catch (\Throwable $e) {
            // Mark as failure
            $this->circuitBreaker->markFailure('your.service');
            
            // Re-throw or handle exception
            throw $e;
        }
    }
}
```

### Custom HTTP Client Fallback

You can create a custom HTTP client fallback factory to handle HTTP requests when the circuit is open:

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

Then create your fallback factory:

```php
<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Component\HttpClient\Response\MockResponse;

class HttpClientFallbackFactory
{
    public function __invoke(string $method, string $url, array $options): ResponseInterface
    {
        // Return different fallback responses based on URL
        if (strpos($url, '/users') !== false) {
            return new MockResponse(json_encode(['users' => []]), [
                'http_code' => 200,
            ]);
        }
        
        // Default fallback response
        return new MockResponse(json_encode([
            'error' => 'Service temporarily unavailable',
            'fallback' => true,
        ]), [
            'http_code' => 503,
        ]);
    }
}
```

## References

- [Ganesha - PHP Circuit Breaker Library](https://github.com/ackintosh/ganesha)
- [Circuit Breaker Pattern](https://martinfowler.com/bliki/CircuitBreaker.html)
- [Microservice Resilience Patterns: Circuit Breaker](https://docs.microsoft.com/en-us/azure/architecture/patterns/circuit-breaker)

## License

This package is open-sourced software licensed under the MIT license. See the [LICENSE](LICENSE) file for more information. 