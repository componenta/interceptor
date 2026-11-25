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
            CallableContextFactoryInterface::class => CallableContextFactoryFactory::class,
            AttributeInterceptor::class => AttributeInterceptorFactory::class,
            PipelineInterface::class => HttpInterceptorPipelineFactory::class,
        ];
    }
}
