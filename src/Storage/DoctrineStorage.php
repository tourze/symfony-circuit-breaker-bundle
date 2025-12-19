<?php

namespace Tourze\Symfony\CircuitBreaker\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Tourze\Symfony\CircuitBreaker\Model\CallResult;
use Tourze\Symfony\CircuitBreaker\Model\CircuitBreakerState;
use Tourze\Symfony\CircuitBreaker\Model\MetricsSnapshot;

/**
 * Doctrine数据库存储实现
 *
 * 作为Redis的备用存储方案
 */
#[WithMonologChannel(channel: 'circuit_breaker')]
final class DoctrineStorage implements CircuitBreakerStorageInterface
{
    private const STATE_TABLE = 'circuit_breaker_state';
    private const METRICS_TABLE = 'circuit_breaker_metrics';
    private const LOCK_TABLE = 'circuit_breaker_locks';

    private bool $tablesChecked = false;

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.circuit_breaker_connection')] private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    private function ensureTablesExist(): void
    {
        if ($this->tablesChecked) {
            return;
        }

        try {
            $schemaManager = $this->connection->createSchemaManager();
            if ($schemaManager->tablesExist([self::STATE_TABLE, self::METRICS_TABLE, self::LOCK_TABLE])) {
                $this->tablesChecked = true;

                return;
            }
            // 创建状态表
            $this->connection->executeStatement('
                CREATE TABLE IF NOT EXISTS ' . self::STATE_TABLE . ' (
                    name VARCHAR(255) PRIMARY KEY,
                    state VARCHAR(50) NOT NULL,
                    timestamp INT NOT NULL,
                    attempt_count INT NOT NULL DEFAULT 0,
                    updated_at INT NOT NULL,
                    INDEX idx_updated_at (updated_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ');

            // 创建指标表
            $this->connection->executeStatement('
                CREATE TABLE IF NOT EXISTS ' . self::METRICS_TABLE . ' (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(255) NOT NULL,
                    success TINYINT(1) NOT NULL,
                    duration FLOAT NOT NULL,
                    timestamp INT NOT NULL,
                    INDEX idx_name_timestamp (name, timestamp),
                    INDEX idx_timestamp (timestamp)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ');

            // 创建锁表
            $this->connection->executeStatement('
                CREATE TABLE IF NOT EXISTS ' . self::LOCK_TABLE . ' (
                    name VARCHAR(255) PRIMARY KEY,
                    token VARCHAR(255) NOT NULL,
                    expire_at INT NOT NULL,
                    INDEX idx_expire_at (expire_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ');

            $this->tablesChecked = true;
        } catch (Exception $e) {
            $this->logger->warning('Failed to ensure tables exist', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function saveState(string $name, CircuitBreakerState $state): bool
    {
        $this->ensureTablesExist();

        try {
            $sql = '
                INSERT INTO ' . self::STATE_TABLE . ' (name, state, timestamp, attempt_count, updated_at)
                VALUES (:name, :state, :timestamp, :attempt_count, :updated_at)
                ON DUPLICATE KEY UPDATE
                    state = VALUES(state),
                    timestamp = VALUES(timestamp),
                    attempt_count = VALUES(attempt_count),
                    updated_at = VALUES(updated_at)
            ';

            $this->connection->executeStatement($sql, [
                'name' => $name,
                'state' => $state->getState()->value,
                'timestamp' => $state->getTimestamp(),
                'attempt_count' => $state->getAttemptCount(),
                'updated_at' => time(),
            ]);

            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to save circuit breaker state to database', [
                'circuit' => $name,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function getState(string $name): CircuitBreakerState
    {
        $this->ensureTablesExist();

        try {
            $sql = 'SELECT state, timestamp, attempt_count FROM ' . self::STATE_TABLE . ' WHERE name = :name';
            $result = $this->connection->executeQuery($sql, ['name' => $name])->fetchAssociative();

            if (false === $result) {
                return new CircuitBreakerState();
            }

            return CircuitBreakerState::fromArray([
                'state' => $result['state'],
                'timestamp' => (int) $result['timestamp'],
                'attemptCount' => (int) $result['attempt_count'],
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to get circuit breaker state from database', [
                'circuit' => $name,
                'error' => $e->getMessage(),
            ]);

            return new CircuitBreakerState();
        }
    }

    public function recordCall(string $name, CallResult $result): void
    {
        $this->ensureTablesExist();

        try {
            $sql = '
                INSERT INTO ' . self::METRICS_TABLE . ' (name, success, duration, timestamp)
                VALUES (:name, :success, :duration, :timestamp)
            ';

            $this->connection->executeStatement($sql, [
                'name' => $name,
                'success' => $result->isSuccess() ? 1 : 0,
                'duration' => $result->getDuration(),
                'timestamp' => $result->getTimestamp(),
            ]);

            // 清理过期数据
            $this->cleanupOldMetrics();
        } catch (Exception $e) {
            $this->logger->error('Failed to record call in database', [
                'circuit' => $name,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function cleanupOldMetrics(): void
    {
        try {
            // 清理超过7天的数据
            $cutoff = time() - (7 * 24 * 60 * 60);
            $this->connection->executeStatement(
                'DELETE FROM ' . self::METRICS_TABLE . ' WHERE timestamp < :cutoff LIMIT 1000',
                ['cutoff' => $cutoff]
            );

            // 清理过期的锁
            $this->connection->executeStatement(
                'DELETE FROM ' . self::LOCK_TABLE . ' WHERE expire_at < :now',
                ['now' => time()]
            );
        } catch (Exception $e) {
            $this->logger->warning('Failed to cleanup old data', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function getMetricsSnapshot(string $name, int $windowSize): MetricsSnapshot
    {
        $this->ensureTablesExist();

        try {
            $windowStart = time() - $windowSize;
            $slowCallThreshold = (float) ($_ENV['CIRCUIT_BREAKER_SLOW_CALL_THRESHOLD'] ?? 1000);

            $sql = '
                SELECT 
                    COUNT(*) as total_calls,
                    SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as success_calls,
                    SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed_calls,
                    SUM(CASE WHEN duration > :slow_threshold THEN 1 ELSE 0 END) as slow_calls,
                    AVG(duration) as avg_duration
                FROM ' . self::METRICS_TABLE . '
                WHERE name = :name AND timestamp > :window_start
            ';

            $result = $this->connection->executeQuery($sql, [
                'name' => $name,
                'window_start' => $windowStart,
                'slow_threshold' => $slowCallThreshold,
            ])->fetchAssociative();

            if (false === $result) {
                return new MetricsSnapshot();
            }

            return new MetricsSnapshot(
                totalCalls: (int) $result['total_calls'],
                successCalls: (int) $result['success_calls'],
                failedCalls: (int) $result['failed_calls'],
                slowCalls: (int) $result['slow_calls'],
                notPermittedCalls: 0,
                avgResponseTime: (float) ($result['avg_duration'] ?? 0),
                timestamp: time()
            );
        } catch (Exception $e) {
            $this->logger->error('Failed to get metrics snapshot from database', [
                'circuit' => $name,
                'error' => $e->getMessage(),
            ]);

            return new MetricsSnapshot();
        }
    }

    public function getAllCircuitNames(): array
    {
        $this->ensureTablesExist();

        try {
            $sql = 'SELECT DISTINCT name FROM ' . self::STATE_TABLE;

            return $this->connection->executeQuery($sql)->fetchFirstColumn();
        } catch (Exception $e) {
            $this->logger->error('Failed to get circuit names from database', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function deleteCircuit(string $name): void
    {
        $this->ensureTablesExist();

        try {
            $this->connection->executeStatement(
                'DELETE FROM ' . self::STATE_TABLE . ' WHERE name = :name',
                ['name' => $name]
            );
            $this->connection->executeStatement(
                'DELETE FROM ' . self::METRICS_TABLE . ' WHERE name = :name',
                ['name' => $name]
            );
            $this->connection->executeStatement(
                'DELETE FROM ' . self::LOCK_TABLE . ' WHERE name = :name',
                ['name' => $name]
            );
        } catch (Exception $e) {
            $this->logger->error('Failed to delete circuit from database', [
                'circuit' => $name,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function acquireLock(string $name, string $token, int $ttl): bool
    {
        $this->ensureTablesExist();

        try {
            $expireAt = time() + $ttl;

            // 尝试插入锁
            $sql = '
                INSERT INTO ' . self::LOCK_TABLE . ' (name, token, expire_at)
                VALUES (:name, :token, :expire_at)
                ON DUPLICATE KEY UPDATE
                    token = IF(expire_at < :now, VALUES(token), token),
                    expire_at = IF(expire_at < :now, VALUES(expire_at), expire_at)
            ';

            $this->connection->executeStatement($sql, [
                'name' => $name,
                'token' => $token,
                'expire_at' => $expireAt,
                'now' => time(),
            ]);

            // 检查是否获取成功
            $checkSql = 'SELECT token FROM ' . self::LOCK_TABLE . ' WHERE name = :name';
            $currentToken = $this->connection->executeQuery($checkSql, ['name' => $name])->fetchOne();

            return $currentToken === $token;
        } catch (Exception $e) {
            $this->logger->error('Failed to acquire lock in database', [
                'circuit' => $name,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function releaseLock(string $name, string $token): bool
    {
        $this->ensureTablesExist();

        try {
            $sql = 'DELETE FROM ' . self::LOCK_TABLE . ' WHERE name = :name AND token = :token';
            $affected = $this->connection->executeStatement($sql, [
                'name' => $name,
                'token' => $token,
            ]);

            return $affected > 0;
        } catch (Exception $e) {
            $this->logger->error('Failed to release lock in database', [
                'circuit' => $name,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function isAvailable(): bool
    {
        try {
            $this->connection->executeQuery('SELECT 1')->fetchOne();

            return true;
        } catch (Exception $e) {
            $this->logger->error('Database connection failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
