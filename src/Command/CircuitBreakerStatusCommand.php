<?php

namespace Tourze\Symfony\CircuitBreaker\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tourze\Symfony\CircuitBreaker\Enum\CircuitState;
use Tourze\Symfony\CircuitBreaker\Service\CircuitBreakerService;

/**
 * 熔断器状态命令
 */
#[AsCommand(
    name: self::NAME,
    description: '查看或管理熔断器状态'
)]
class CircuitBreakerStatusCommand extends Command
{
    /**
     * 命令名称
     */
    public const NAME = 'circuit-breaker:status';

    /**
     * @param CircuitBreakerService $circuitBreakerService 熔断器服务
     */
    public function __construct(
        private readonly CircuitBreakerService $circuitBreakerService
    )
    {
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::OPTIONAL, '熔断器名称')
            ->addOption('reset', 'r', InputOption::VALUE_NONE, '重置指定熔断器状态')
            ->addOption('force-open', 'o', InputOption::VALUE_NONE, '强制打开指定熔断器')
            ->addOption('force-close', 'c', InputOption::VALUE_NONE, '强制关闭指定熔断器')
            ->addOption('config', null, InputOption::VALUE_NONE, '显示熔断器配置信息')
            ->addOption('list', 'l', InputOption::VALUE_NONE, '列出所有已知的熔断器');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $circuitName = $input->getArgument('name');
        $config = $this->circuitBreakerService->getConfig();

        // 显示配置信息
        if ($input->getOption('config')) {
            $this->displayConfig($io, $config);
            return Command::SUCCESS;
        }

        // 列出所有已知的熔断器
        if ($input->getOption('list')) {
            $this->listCircuits($io, $config);
            return Command::SUCCESS;
        }

        // 如果指定了熔断器名称
        if ($circuitName) {
            // 执行命令操作
            if ($input->getOption('reset')) {
                $this->resetCircuit($io, $circuitName);
                return Command::SUCCESS;
            }

            if ($input->getOption('force-open')) {
                $this->forceOpenCircuit($io, $circuitName);
                return Command::SUCCESS;
            }

            if ($input->getOption('force-close')) {
                $this->forceCloseCircuit($io, $circuitName);
                return Command::SUCCESS;
            }

            // 显示熔断器状态
            $this->displayCircuitStatus($io, $circuitName);
        } else {
            $io->note('没有指定熔断器名称，无法显示具体状态。请使用 --config 选项查看配置信息，或使用 --list 选项列出已知熔断器。');
        }

        return Command::SUCCESS;
    }

    /**
     * 显示熔断器配置信息
     */
    private function displayConfig(SymfonyStyle $io, array $config): void
    {
        $io->title('熔断器全局配置信息');

        $io->section('缓存配置');
        $io->table(
            ['配置项', '值'],
            [
                ['metrics_cache_ttl', $config['metrics_cache_ttl']],
                ['state_cache_ttl', $config['state_cache_ttl']],
            ]
        );

        $io->section('Redis配置');
        $redisConfig = $config['redis'] ?? [];
        
        $rows = [];
        foreach ($redisConfig as $key => $value) {
            if ($key === 'password' && !empty($value)) {
                $value = '******';
            }
            $rows[] = [$key, $value];
        }

        $io->table(
            ['配置项', '值'],
            $rows
        );

        $io->section('默认熔断器配置');
        $rows = [];
        foreach ($config['default_circuit'] as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            } elseif (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            $rows[] = [$key, $value];
        }

        $io->table(
            ['配置项', '值'],
            $rows
        );

        if (!empty($config['circuits'])) {
            $io->section('特定熔断器配置');

            foreach ($config['circuits'] as $name => $circuitConfig) {
                $io->writeln("<info>{$name}</info>:");

                $rows = [];
                foreach ($circuitConfig as $key => $value) {
                    if (is_array($value)) {
                        $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                    } elseif (is_bool($value)) {
                        $value = $value ? 'true' : 'false';
                    }
                    $rows[] = [$key, $value];
                }

                $io->table(
                    ['配置项', '值'],
                    $rows
                );
            }
        }
    }

    /**
     * 列出所有已知熔断器
     */
    private function listCircuits(SymfonyStyle $io, array $config): void
    {
        $io->title('已知的熔断器');

        // 从存储中获取所有熔断器
        $circuits = $this->circuitBreakerService->getAllCircuitNames();

        // 如果没有找到任何熔断器
        if (empty($circuits)) {
            $io->warning('没有找到任何熔断器记录。');

            // 显示已配置的熔断器
            if (!empty($config['circuits'])) {
                $io->section('以下是配置文件中已定义的熔断器:');
                $rows = [];
                foreach (array_keys($config['circuits']) as $name) {
                    $rows[] = [$name, '未调用', 'N/A', 'N/A'];
                }

                $io->table(
                    ['熔断器名称', '当前状态', '失败率', '调用次数'],
                    $rows
                );
            }

            return;
        }

        $rows = [];
        foreach ($circuits as $name) {
            try {
                $info = $this->circuitBreakerService->getCircuitInfo($name);
                $state = $info['state'];

                // 美化状态显示
                $stateDisplay = match ($state) {
                    CircuitState::CLOSED->value => '关闭（正常）',
                    CircuitState::OPEN->value => '打开（熔断中）',
                    CircuitState::HALF_OPEN->value => '半开（尝试恢复）',
                    default => $state,
                };

                $failureRate = isset($info['metrics']['failureRate']) ?
                    round($info['metrics']['failureRate'], 2) . '%' : 'N/A';
                $calls = $info['metrics']['numberOfCalls'] ?? 0;

                $rows[] = [
                    $name,
                    $stateDisplay,
                    $failureRate,
                    $calls,
                ];
            } catch (\Exception $e) {
                $rows[] = [$name, '无法获取状态', 'N/A', 'N/A'];
            }
        }

        $io->table(
            ['熔断器名称', '当前状态', '失败率', '调用次数'],
            $rows
        );
    }

    /**
     * 显示熔断器状态
     */
    private function displayCircuitStatus(SymfonyStyle $io, string $name): void
    {
        try {
            $info = $this->circuitBreakerService->getCircuitInfo($name);

            $io->title("熔断器 '$name' 的状态");

            // 显示状态
            $state = $info['state'];
            $timestamp = date('Y-m-d H:i:s', $info['timestamp']);

            if ($state === CircuitState::CLOSED->value) {
                $io->success("熔断器状态: 关闭 (允许请求通过)");
            } elseif ($state === CircuitState::OPEN->value) {
                $io->error("熔断器状态: 打开 (请求被拒绝)");
            } else {
                $io->warning("熔断器状态: 半开 (有限请求通过)");
            }

            $io->writeln("最后状态更新时间: <info>{$timestamp}</info>");

            // 显示指标
            $io->section('统计指标');

            $metrics = $info['metrics'];
            $io->table(
                ['指标', '值'],
                [
                    ['总调用次数', $metrics['numberOfCalls'] ?? 0],
                    ['成功调用次数', $metrics['numberOfSuccessfulCalls'] ?? 0],
                    ['失败调用次数', $metrics['numberOfFailedCalls'] ?? 0],
                    ['被拒绝次数', $metrics['notPermittedCalls'] ?? 0],
                    ['失败率', isset($metrics['failureRate']) ? round($metrics['failureRate'], 2) . '%' : '0%'],
                ]
            );

            // 显示配置
            $io->section('熔断器配置');

            $config = $info['config'];
            $rows = [];
            foreach ($config as $key => $value) {
                if (is_array($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                } elseif (is_bool($value)) {
                    $value = $value ? 'true' : 'false';
                }
                $rows[] = [$key, $value];
            }

            $io->table(
                ['配置项', '值'],
                $rows
            );
        } catch (\Exception $e) {
            $io->error("无法获取熔断器 '$name' 的状态: " . $e->getMessage());
        }
    }

    /**
     * 重置熔断器状态
     */
    private function resetCircuit(SymfonyStyle $io, string $name): void
    {
        try {
            $this->circuitBreakerService->resetCircuit($name);
            $io->success("熔断器 '$name' 的状态已重置");
        } catch (\Exception $e) {
            $io->error("重置熔断器 '$name' 的状态时出错: " . $e->getMessage());
        }
    }

    /**
     * 强制打开熔断器
     */
    private function forceOpenCircuit(SymfonyStyle $io, string $name): void
    {
        try {
            $this->circuitBreakerService->forceOpen($name);
            $io->success("熔断器 '$name' 已被强制打开");
        } catch (\Exception $e) {
            $io->error("强制打开熔断器 '$name' 时出错: " . $e->getMessage());
        }
    }

    /**
     * 强制关闭熔断器
     */
    private function forceCloseCircuit(SymfonyStyle $io, string $name): void
    {
        try {
            $this->circuitBreakerService->forceClose($name);
            $io->success("熔断器 '$name' 已被强制关闭");
        } catch (\Exception $e) {
            $io->error("强制关闭熔断器 '$name' 时出错: " . $e->getMessage());
        }
    }
}
