<?php

declare(strict_types=1);

namespace Componenta\Interceptor\Factory;

use Componenta\Config\ContainerValue;
use Componenta\DI\FactoryInterface;
use Componenta\Interceptor\AttributeInterceptor;

final readonly class AttributeInterceptorFactory
{
    public function __invoke(ContainerValue $container): AttributeInterceptor
    {
        return new AttributeInterceptor($container->get(FactoryInterface::class, FactoryInterface::class));
    }
}
