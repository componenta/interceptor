<?php

declare(strict_types=1);

namespace Componenta\Interceptor;

use Componenta\Interceptor\Factory\AttributeInterceptorFactory;
use Componenta\Interceptor\Factory\CallableContextFactoryFactory;
use Componenta\Interceptor\Factory\HttpInterceptorPipelineFactory;

class ConfigProvider extends \Componenta\Config\ConfigProvider
{
    protected function getFactories(): array
    {
        return [
            CallableContextFactory::class => CallableContextFactoryFactory::class,
            AttributeInterceptor::class => AttributeInterceptorFactory::class,
            PipelineInterface::class => HttpInterceptorPipelineFactory::class,
        ];
    }

    protected function getAliases(): array
    {
        return [
            CallableContextFactoryInterface::class => CallableContextFactory::class,
        ];
    }
}
