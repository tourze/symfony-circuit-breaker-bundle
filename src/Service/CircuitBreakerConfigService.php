<?php

namespace Tourze\Symfony\CircuitBreaker\Service;

/**
 * 熔断器配置服务
 */
class CircuitBreakerConfigService
{
    /**
     * 获取指定熔断器的配置
     */
    public function getCircuitConfig(string $name): array
    {
        // 从环境变量中读取默认配置
        $defaultConfig = [
            'failure_rate_threshold' => (int)($_ENV['CIRCUIT_BREAKER_FAILURE_RATE_THRESHOLD'] ?? 50),
            'minimum_number_of_calls' => (int)($_ENV['CIRCUIT_BREAKER_MINIMUM_NUMBER_OF_CALLS'] ?? 100),
            'permitted_number_of_calls_in_half_open_state' => (int)($_ENV['CIRCUIT_BREAKER_PERMITTED_NUMBER_OF_CALLS_IN_HALF_OPEN_STATE'] ?? 10),
            'wait_duration_in_open_state' => (int)($_ENV['CIRCUIT_BREAKER_WAIT_DURATION_IN_OPEN_STATE'] ?? 60),
            'ignore_exceptions' => $this->parseExceptionsList($_ENV['CIRCUIT_BREAKER_IGNORE_EXCEPTIONS'] ?? ''),
            'record_exceptions' => $this->parseExceptionsList($_ENV['CIRCUIT_BREAKER_RECORD_EXCEPTIONS'] ?? '')
        ];
        
        // 获取特定熔断器配置
        // 由于环境变量不能很好地支持动态键值，我们可以使用特定命名约定
        // 例如：CIRCUIT_BREAKER_CIRCUIT_{NAME}_{CONFIG_KEY}
        $prefix = 'CIRCUIT_BREAKER_CIRCUIT_' . strtoupper($name) . '_';
        $specificConfig = [];
        
        foreach ($defaultConfig as $key => $value) {
            $envKey = $prefix . strtoupper($key);
            if (isset($_ENV[$envKey])) {
                if (in_array($key, ['ignore_exceptions', 'record_exceptions'])) {
                    $specificConfig[$key] = $this->parseExceptionsList($_ENV[$envKey]);
                } else {
                    $specificConfig[$key] = (int)$_ENV[$envKey];
                }
            }
        }
        
        return array_merge($defaultConfig, $specificConfig);
    }
    
    /**
     * 解析异常类列表字符串
     */
    private function parseExceptionsList(string $exceptionsList): array
    {
        if (empty($exceptionsList)) {
            return [];
        }
        
        return array_map('trim', explode(',', $exceptionsList));
    }
    
    /**
     * 获取当前配置
     */
    public function getConfig(): array
    {
        // 基本配置
        $config = [
            'metrics_cache_ttl' => (int)($_ENV['CIRCUIT_BREAKER_METRICS_CACHE_TTL'] ?? 3600),
            'state_cache_ttl' => (int)($_ENV['CIRCUIT_BREAKER_STATE_CACHE_TTL'] ?? 86400),
            'redis' => [
                'host' => $_ENV['CIRCUIT_BREAKER_REDIS_HOST'] ?? 'localhost',
                'port' => (int)($_ENV['CIRCUIT_BREAKER_REDIS_PORT'] ?? 6379),
                'password' => $_ENV['CIRCUIT_BREAKER_REDIS_PASSWORD'] ?? null,
                'database' => (int)($_ENV['CIRCUIT_BREAKER_REDIS_DATABASE'] ?? 0),
            ],
        ];
        
        // 默认熔断器配置
        $config['default_circuit'] = [
            'failure_rate_threshold' => (int)($_ENV['CIRCUIT_BREAKER_FAILURE_RATE_THRESHOLD'] ?? 50),
            'minimum_number_of_calls' => (int)($_ENV['CIRCUIT_BREAKER_MINIMUM_NUMBER_OF_CALLS'] ?? 100),
            'permitted_number_of_calls_in_half_open_state' => (int)($_ENV['CIRCUIT_BREAKER_PERMITTED_NUMBER_OF_CALLS_IN_HALF_OPEN_STATE'] ?? 10),
            'wait_duration_in_open_state' => (int)($_ENV['CIRCUIT_BREAKER_WAIT_DURATION_IN_OPEN_STATE'] ?? 60),
            'ignore_exceptions' => $this->parseExceptionsList($_ENV['CIRCUIT_BREAKER_IGNORE_EXCEPTIONS'] ?? ''),
            'record_exceptions' => $this->parseExceptionsList($_ENV['CIRCUIT_BREAKER_RECORD_EXCEPTIONS'] ?? '')
        ];
        
        // 获取所有特定的熔断器配置
        // 查找所有以 CIRCUIT_BREAKER_CIRCUIT_ 开头的环境变量，找出所有熔断器名称
        $circuitNames = [];
        foreach ($_ENV as $key => $value) {
            if (strpos($key, 'CIRCUIT_BREAKER_CIRCUIT_') === 0) {
                $parts = explode('_', substr($key, strlen('CIRCUIT_BREAKER_CIRCUIT_')));
                if (count($parts) >= 2) {
                    $circuitName = strtolower($parts[0]);
                    $circuitNames[$circuitName] = true;
                }
            }
        }
        
        // 构建特定熔断器配置
        $circuits = [];
        foreach (array_keys($circuitNames) as $name) {
            $circuits[$name] = $this->getCircuitConfig($name);
        }
        
        $config['circuits'] = $circuits;
        
        return $config;
    }
} 