<?php

namespace Tourze\Symfony\CircuitBreaker\Service;

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

/**
 * 熔断器配置服务
 */
#[Autoconfigure]
final class CircuitBreakerConfigService
{
    /**
     * 获取指定熔断器的配置
     *
     * @return array<string, mixed>
     */
    public function getCircuitConfig(string $name): array
    {
        $defaultConfig = $this->getDefaultConfig();
        $specificConfig = $this->getSpecificConfig($name, $defaultConfig);

        return array_merge($defaultConfig, $specificConfig);
    }

    /**
     * 获取默认配置
     *
     * @return array<string, mixed>
     */
    private function getDefaultConfig(): array
    {
        return [
            'failure_rate_threshold' => (int) ($_ENV['CIRCUIT_BREAKER_FAILURE_RATE_THRESHOLD'] ?? 50),
            'minimum_number_of_calls' => (int) ($_ENV['CIRCUIT_BREAKER_MINIMUM_NUMBER_OF_CALLS'] ?? 10),
            'permitted_number_of_calls_in_half_open_state' => (int) ($_ENV['CIRCUIT_BREAKER_PERMITTED_NUMBER_OF_CALLS_IN_HALF_OPEN_STATE'] ?? 10),
            'wait_duration_in_open_state' => (int) ($_ENV['CIRCUIT_BREAKER_WAIT_DURATION_IN_OPEN_STATE'] ?? 60),
            'sliding_window_size' => (int) ($_ENV['CIRCUIT_BREAKER_SLIDING_WINDOW_SIZE'] ?? 60),
            'slow_call_duration_threshold' => (float) ($_ENV['CIRCUIT_BREAKER_SLOW_CALL_THRESHOLD'] ?? 1000),
            'slow_call_rate_threshold' => (float) ($_ENV['CIRCUIT_BREAKER_SLOW_CALL_RATE_THRESHOLD'] ?? 50),
            'strategy' => $_ENV['CIRCUIT_BREAKER_STRATEGY'] ?? 'failure_rate',
            'ignore_exceptions' => $this->parseExceptionsList($_ENV['CIRCUIT_BREAKER_IGNORE_EXCEPTIONS'] ?? ''),
            'record_exceptions' => $this->parseExceptionsList($_ENV['CIRCUIT_BREAKER_RECORD_EXCEPTIONS'] ?? ''),
        ];
    }

    /**
     * 获取特定熔断器配置
     *
     * @param array<string, mixed> $defaultConfig
     * @return array<string, mixed>
     */
    private function getSpecificConfig(string $name, array $defaultConfig): array
    {
        $prefix = 'CIRCUIT_' . strtoupper(str_replace('.', '_', $name)) . '_';
        $specificConfig = [];

        foreach ($defaultConfig as $key => $value) {
            $envKey = $prefix . strtoupper($key);
            if (!isset($_ENV[$envKey])) {
                continue;
            }

            $specificConfig[$key] = $this->parseConfigValue($key, $_ENV[$envKey], $value);
        }

        return $specificConfig;
    }

    /**
     * 解析配置值
     */
    private function parseConfigValue(string $key, string $envValue, mixed $defaultValue): mixed
    {
        if (in_array($key, ['ignore_exceptions', 'record_exceptions'], true)) {
            return $this->parseExceptionsList($envValue);
        }

        if ('strategy' === $key) {
            return $envValue;
        }

        if (is_float($defaultValue)) {
            return (float) $envValue;
        }

        return (int) $envValue;
    }

    /**
     * 解析异常类列表字符串
     *
     * @return array<string>
     */
    private function parseExceptionsList(string $exceptionsList): array
    {
        if ('' === $exceptionsList) {
            return [];
        }

        return array_map('trim', explode(',', $exceptionsList));
    }

    /**
     * 获取当前配置
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        // 基本配置
        $config = [
            'metrics_cache_ttl' => (int) ($_ENV['CIRCUIT_BREAKER_METRICS_CACHE_TTL'] ?? 3600),
            'state_cache_ttl' => (int) ($_ENV['CIRCUIT_BREAKER_STATE_CACHE_TTL'] ?? 86400),
            'redis' => [
                'host' => $_ENV['CIRCUIT_BREAKER_REDIS_HOST'] ?? 'localhost',
                'port' => (int) ($_ENV['CIRCUIT_BREAKER_REDIS_PORT'] ?? 6379),
                'password' => $_ENV['CIRCUIT_BREAKER_REDIS_PASSWORD'] ?? null,
                'database' => (int) ($_ENV['CIRCUIT_BREAKER_REDIS_DATABASE'] ?? 0),
            ],
        ];

        // 默认熔断器配置
        $config['default_circuit'] = [
            'failure_rate_threshold' => (int) ($_ENV['CIRCUIT_BREAKER_FAILURE_RATE_THRESHOLD'] ?? 50),
            'minimum_number_of_calls' => (int) ($_ENV['CIRCUIT_BREAKER_MINIMUM_NUMBER_OF_CALLS'] ?? 100),
            'permitted_number_of_calls_in_half_open_state' => (int) ($_ENV['CIRCUIT_BREAKER_PERMITTED_NUMBER_OF_CALLS_IN_HALF_OPEN_STATE'] ?? 10),
            'wait_duration_in_open_state' => (int) ($_ENV['CIRCUIT_BREAKER_WAIT_DURATION_IN_OPEN_STATE'] ?? 60),
            'ignore_exceptions' => $this->parseExceptionsList($_ENV['CIRCUIT_BREAKER_IGNORE_EXCEPTIONS'] ?? ''),
            'record_exceptions' => $this->parseExceptionsList($_ENV['CIRCUIT_BREAKER_RECORD_EXCEPTIONS'] ?? ''),
        ];

        // 获取所有特定的熔断器配置
        // 查找所有以 CIRCUIT_BREAKER_CIRCUIT_ 开头的环境变量，找出所有熔断器名称
        $circuitNames = [];
        foreach ($_ENV as $key => $value) {
            if (0 === strpos($key, 'CIRCUIT_BREAKER_CIRCUIT_')) {
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

        // 存储配置
        $config['storage'] = [
            'primary' => $_ENV['CIRCUIT_BREAKER_STORAGE_PRIMARY'] ?? 'redis',
            'fallback' => $_ENV['CIRCUIT_BREAKER_STORAGE_FALLBACK'] ?? 'doctrine',
            'redis_key_prefix' => $_ENV['CIRCUIT_BREAKER_REDIS_KEY_PREFIX'] ?? 'circuit:',
            'doctrine_table_prefix' => $_ENV['CIRCUIT_BREAKER_DOCTRINE_TABLE_PREFIX'] ?? 'circuit_breaker_',
        ];

        return $config;
    }
}
