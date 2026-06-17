<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\Interceptor\CallableContext;
use Componenta\Interceptor\CallableContextInterface;
use Componenta\Interceptor\ConfigKey;
use Componenta\Interceptor\ConfigProvider;
use Componenta\Interceptor\ContextHandlerInterface;
use Componenta\Interceptor\Factory\HttpInterceptorPipelineFactory;
use Componenta\Interceptor\InterceptorInterface;
use Componenta\Interceptor\InterceptingExecutor;
use Componenta\Interceptor\PipelineInterface;
use Componenta\Interceptor\Tests\Fixture\PassThroughExecutor;
use Componenta\DI\ConfigKey as DependencyConfigKey;
use Psr\Container\ContainerInterface;

final readonly class HttpInterceptorPipelineFactoryTestContainer implements ContainerInterface
{
    /**
     * @param array<string, mixed> $entries
     */
    public function __construct(private array $entries) {}

    public function get(string $id): mixed
    {
        if (!$this->has($id)) {
            throw new RuntimeException("Missing container entry: {$id}");
        }

        return $this->entries[$id];
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->entries);
    }
}

final readonly class HttpInterceptorPipelineFactoryTestInterceptor implements InterceptorInterface
{
    public function intercept(CallableContextInterface $context, ContextHandlerInterface $handler): mixed
    {
        return 'wrapped:' . $handler->handle($context);
    }
}

describe('HTTP interceptor pipeline factory', function () {
    it('registers the pipeline factory in the interceptor config provider', function () {
        $config = (new ConfigProvider())();

        expect($config[DependencyConfigKey::DEPENDENCIES][DependencyConfigKey::FACTORIES][PipelineInterface::class])
            ->toBe(HttpInterceptorPipelineFactory::class);
    });

    it('builds an HTTP pipeline from configured interceptor service ids', function () {
        $interceptor = new HttpInterceptorPipelineFactoryTestInterceptor();
        $container = new HttpInterceptorPipelineFactoryTestContainer([
            Config::class => new Config([
                ConfigKey::HTTP_INTERCEPTORS => [
                    HttpInterceptorPipelineFactoryTestInterceptor::class,
                ],
            ]),
            Componenta\DI\CallableExecutorInterface::class => new PassThroughExecutor(),
            ParametersResolver::class => new ParametersResolver(),
            HttpInterceptorPipelineFactoryTestInterceptor::class => $interceptor,
        ]);

        $pipeline = (new HttpInterceptorPipelineFactory())($container);

        expect($pipeline)->toBeInstanceOf(InterceptingExecutor::class)
            ->and($pipeline->handle(new CallableContext(static fn (): string => 'ok')))->toBe('wrapped:ok');
    });
});
