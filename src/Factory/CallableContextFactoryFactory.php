<?php

declare(strict_types=1);

namespace Componenta\Interceptor\Factory;

use Componenta\Config\ContainerValue;
use Componenta\DI\CallableResolverInterface;
use Componenta\Interceptor\CallableContextFactory;

final readonly class CallableContextFactoryFactory
{
    public function __invoke(ContainerValue $container): CallableContextFactory
    {
        return new CallableContextFactory($container->get(CallableResolverInterface::class, CallableResolverInterface::class));
    }
}
