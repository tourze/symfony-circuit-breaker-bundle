<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tourze\Symfony\CircuitBreaker\DependencyInjection\CircuitBreakerExtension;

class CircuitBreakerExtensionTest extends TestCase
{
    private CircuitBreakerExtension $extension;
    private ContainerBuilder $container;
    
    protected function setUp(): void
    {
        $this->extension = new CircuitBreakerExtension();
        $this->container = new ContainerBuilder();
    }
    
    public function testLoad_withDefaultConfiguration_registersServices(): void
    {
        $config = [];
        
        $this->extension->load($config, $this->container);
        
        // 测试服务配置文件是否成功加载
        $this->assertNotEmpty($this->container->getDefinitions(), 'Container should have service definitions after loading');
        
        // 检查是否有自动配置的服务 - 检查具体的实现类而不是接口
        $hasDefinitions = $this->container->hasDefinition('Tourze\\Symfony\\CircuitBreaker\\Storage\\ChainedStorage') ||
                         $this->container->hasDefinition('Tourze\\Symfony\\CircuitBreaker\\Storage\\MemoryStorage');
        
        $this->assertTrue($hasDefinitions, 'Should have storage service definitions');
    }
    
    public function testGetAlias_returnsCorrectAlias(): void
    {
        $this->assertEquals('circuit_breaker', $this->extension->getAlias());
    }
}