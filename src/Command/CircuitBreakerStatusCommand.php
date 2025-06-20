<?php

namespace Tourze\Symfony\CircuitBreaker\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tourze\Symfony\CircuitBreaker\Service\CircuitBreakerRegistry;
use Tourze\Symfony\CircuitBreaker\Service\CircuitBreakerService;

#[AsCommand(
    name: self::NAME,
    description: '查看和管理熔断器状态',
)]
class CircuitBreakerStatusCommand extends Command
{
    public const NAME = 'circuit-breaker:status';
    public function __construct(
        private readonly CircuitBreakerService $circuitBreaker,
        private readonly CircuitBreakerRegistry $registry
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('service', InputArgument::OPTIONAL, '服务名称，不提供则显示所有服务状态')
            ->addOption('reset', null, InputOption::VALUE_NONE, '重置熔断器状态')
            ->addOption('force-open', null, InputOption::VALUE_NONE, '强制打开熔断器')
            ->addOption('force-close', null, InputOption::VALUE_NONE, '强制关闭熔断器')
            ->addOption('health', null, InputOption::VALUE_NONE, '显示健康状态摘要')
            ->addOption('json', null, InputOption::VALUE_NONE, '以JSON格式输出')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $serviceName = $input->getArgument('service');
        $jsonOutput = $input->getOption('json');

        // 显示健康状态
        if ($input->getOption('health') === true) {
            return $this->showHealthStatus($io, $jsonOutput === true);
        }

        // 如果没有指定服务名，显示所有服务状态
        if ($serviceName === null) {
            return $this->showAllServicesStatus($io, $jsonOutput === true);
        }

        // 处理重置操作
        if ($input->getOption('reset') === true) {
            $this->circuitBreaker->resetCircuit($serviceName);
            $io->success(sprintf('熔断器 "%s" 已重置', $serviceName));
            return Command::SUCCESS;
        }

        // 处理强制打开
        if ($input->getOption('force-open') === true) {
            $this->circuitBreaker->forceOpen($serviceName);
            $io->success(sprintf('熔断器 "%s" 已强制打开', $serviceName));
            return Command::SUCCESS;
        }

        // 处理强制关闭
        if ($input->getOption('force-close') === true) {
            $this->circuitBreaker->forceClose($serviceName);
            $io->success(sprintf('熔断器 "%s" 已强制关闭', $serviceName));
            return Command::SUCCESS;
        }

        // 显示特定服务状态
        return $this->showServiceStatus($io, $serviceName, $jsonOutput === true);
    }

    private function showHealthStatus(SymfonyStyle $io, bool $jsonOutput): int
    {
        $health = $this->registry->getHealthStatus();

        if ($jsonOutput) {
            $io->writeln(json_encode($health, JSON_PRETTY_PRINT));
            return Command::SUCCESS;
        }

        $io->title('熔断器健康状态');
        
        $rows = [
            ['总熔断器数', $health['total_circuits']],
            ['开启状态', sprintf('<error>%d</error>', $health['open_circuits'])],
            ['半开状态', sprintf('<comment>%d</comment>', $health['half_open_circuits'])],
            ['关闭状态', sprintf('<info>%d</info>', $health['closed_circuits'])],
            ['存储类型', $health['storage_type']],
            ['存储可用', $health['storage_available'] ? '<info>是</info>' : '<error>否</error>'],
        ];

        $io->table(['指标', '值'], $rows);

        if ($health['open_circuits'] > 0) {
            $io->warning('存在处于开启状态的熔断器，部分服务可能不可用');
        } else {
            $io->success('所有熔断器运行正常');
        }

        return Command::SUCCESS;
    }

    private function showAllServicesStatus(SymfonyStyle $io, bool $jsonOutput): int
    {
        $circuits = $this->registry->getAllCircuitsInfo();
        
        if ($jsonOutput) {
            $io->writeln(json_encode($circuits, JSON_PRETTY_PRINT));
            return Command::SUCCESS;
        }

        if (empty($circuits)) {
            $io->info('暂无任何熔断器数据');
            return Command::SUCCESS;
        }

        $headers = ['服务名称', '状态', '总调用', '成功', '失败', '失败率', '慢调用', '拒绝', '存储'];
        $rows = [];

        foreach ($circuits as $name => $info) {
            $metrics = $info['metrics'] ?? [];
            
            $rows[] = [
                $name,
                $this->getStateLabel($info['state'] ?? 'unknown'),
                $metrics['total_calls'] ?? 0,
                $metrics['success_calls'] ?? 0,
                $metrics['failed_calls'] ?? 0,
                sprintf('%.1f%%', $metrics['failure_rate'] ?? 0),
                $metrics['slow_calls'] ?? 0,
                $metrics['not_permitted_calls'] ?? 0,
                $info['storage'] ?? 'unknown',
            ];
        }

        $io->table($headers, $rows);
        return Command::SUCCESS;
    }

    private function showServiceStatus(SymfonyStyle $io, string $serviceName, bool $jsonOutput): int
    {
        try {
            $info = $this->registry->getCircuitInfo($serviceName);
            
            if ($jsonOutput) {
                $io->writeln(json_encode($info, JSON_PRETTY_PRINT));
                return Command::SUCCESS;
            }
            
            $io->title(sprintf('熔断器状态: %s', $serviceName));
            
            $rows = [
                ['状态', $this->getStateLabel($info['state'] ?? 'unknown')],
                ['上次状态变更', date('Y-m-d H:i:s', $info['state_timestamp'] ?? 0)],
                ['存储类型', $info['storage'] ?? 'unknown'],
            ];
            
            if (isset($info['metrics'])) {
                $metrics = $info['metrics'];
                $rows[] = ['---指标信息---', ''];
                $rows[] = ['总调用次数', $metrics['total_calls'] ?? 0];
                $rows[] = ['成功次数', $metrics['success_calls'] ?? 0];
                $rows[] = ['失败次数', $metrics['failed_calls'] ?? 0];
                $rows[] = ['失败率', sprintf('%.1f%%', $metrics['failure_rate'] ?? 0)];
                $rows[] = ['慢调用次数', $metrics['slow_calls'] ?? 0];
                $rows[] = ['慢调用率', sprintf('%.1f%%', $metrics['slow_call_rate'] ?? 0)];
                $rows[] = ['平均响应时间', sprintf('%.2fms', $metrics['avg_response_time'] ?? 0)];
                $rows[] = ['被拒绝的调用', $metrics['not_permitted_calls'] ?? 0];
            }
            
            if (isset($info['config'])) {
                $config = $info['config'];
                $rows[] = ['---配置信息---', ''];
                $rows[] = ['策略', $config['strategy'] ?? 'failure_rate'];
                $rows[] = ['失败率阈值', sprintf('%d%%', $config['failure_rate_threshold'] ?? 50)];
                $rows[] = ['最小调用次数', $config['minimum_number_of_calls'] ?? 0];
                $rows[] = ['半开状态允许调用数', $config['permitted_number_of_calls_in_half_open_state'] ?? 0];
                $rows[] = ['开启状态等待时间', sprintf('%d秒', $config['wait_duration_in_open_state'] ?? 0)];
                $rows[] = ['滑动窗口大小', sprintf('%d秒', $config['sliding_window_size'] ?? 0)];
                $rows[] = ['慢调用阈值', sprintf('%.0fms', $config['slow_call_duration_threshold'] ?? 0)];
                $rows[] = ['慢调用率阈值', sprintf('%.0f%%', $config['slow_call_rate_threshold'] ?? 0)];
            }
            
            $io->table(['属性', '值'], $rows);
            
        } catch (\Exception $e) {
            $io->error(sprintf('无法获取服务 "%s" 的状态: %s', $serviceName, $e->getMessage()));
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function getStateLabel(string $state): string
    {
        return match ($state) {
            'closed' => '<info>关闭</info>',
            'open' => '<error>开启</error>',
            'half_open' => '<comment>半开</comment>',
            default => '<comment>未知</comment>',
        };
    }
}