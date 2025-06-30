<?php

namespace Tourze\Symfony\CircuitBreaker\Tests\Integration\Service;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\RouteCollection;
use Tourze\Symfony\CircuitBreaker\Service\AttributeControllerLoader;

class AttributeControllerLoaderTest extends TestCase
{
    private AttributeControllerLoader $loader;
    
    protected function setUp(): void
    {
        $this->loader = new AttributeControllerLoader();
    }
    
    public function testLoad_returnsRouteCollection(): void
    {
        $result = $this->loader->load('resource');
        
        $this->assertInstanceOf(RouteCollection::class, $result);
    }
    
    public function testAutoload_returnsEmptyRouteCollection(): void
    {
        $result = $this->loader->autoload();
        
        $this->assertInstanceOf(RouteCollection::class, $result);
        $this->assertCount(0, $result);
    }
    
    public function testSupports_returnsFalse(): void
    {
        $result = $this->loader->supports('resource');
        
        $this->assertFalse($result);
    }
}