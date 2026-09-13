<?php

declare(strict_types=1);

namespace Componenta\Interceptor\Factory;

use Componenta\Config\ContainerValue;
use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\Interceptor\ConfigKey;
use Componenta\Interceptor\InterceptingExecutor;
use Componenta\Interceptor\InterceptorInterface;
use Componenta\Interceptor\ParameterResolvingInterceptor;
use Componenta\Interceptor\PipelineInterface;
use RuntimeException;

final readonly class HttpInterceptorPipelineFactory
{
    public function __invoke(ContainerValue $container): PipelineInterface
    {
        $pipeline = new InterceptingExecutor(
            $container->get(CallableExecutorInterface::class, CallableExecutorInterface::class),
            new ParameterResolvingInterceptor($container->get(ParametersResolver::class, ParametersResolver::class)),
        );
        $interceptors = $container->config->get(ConfigKey::HTTP_INTERCEPTORS, []);
        if (!is_array($interceptors)) {
            throw new RuntimeException(sprintf('Config key "%s" must contain a list of interceptor service ids.', ConfigKey::HTTP_INTERCEPTORS));
        }
        foreach ($interceptors as $interceptor) {
            if (is_string($interceptor)) {
                $interceptor = $container->get($interceptor);
            }
            if (!$interceptor instanceof InterceptorInterface) {
                throw new RuntimeException(sprintf(
                    'HTTP interceptor must be a service id or an instance of %s, got %s.',
                    InterceptorInterface::class,
                    get_debug_type($interceptor),
                ));
            }
            $pipeline = $pipeline->pipe($interceptor);
        }
        return $pipeline;
    }
}
