<?php

declare(strict_types=1);

namespace Componenta\Interceptor\Factory;

use Componenta\DI\CallableResolverInterface;
use Componenta\Interceptor\CallableContextFactory;
use Psr\Container\ContainerInterface;

final readonly class CallableContextFactoryFactory
{
    public function __invoke(ContainerInterface $container): CallableContextFactory
    {
        return new CallableContextFactory(
            $container->get(CallableResolverInterface::class),
        );
    }
}
