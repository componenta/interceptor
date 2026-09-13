<?php

declare(strict_types=1);
use Componenta\Config\ConfigFactory;
use Componenta\Config\Environment;
use Componenta\Interceptor\Attribute\Intercept;
use Componenta\Interceptor\AttributeInterceptor;
use Componenta\Interceptor\CallableContext;
use Componenta\Interceptor\CallableContextInterface;
use Componenta\Interceptor\ConfigKey;
use Componenta\Interceptor\ConfigProvider;
use Componenta\Interceptor\ContextHandlerInterface;
use Componenta\Interceptor\InterceptorInterface;
use Componenta\Interceptor\PipelineInterface;
use Componenta\DI\ContainerFactory;

final class FactoryWiredInterceptor implements InterceptorInterface
{
    public function intercept(CallableContextInterface $context, ContextHandlerInterface $handler): mixed
    {
        return 'wired:' . $handler->handle($context);
    }
}
final class FactoryWiredTarget
{
    #[Intercept(FactoryWiredInterceptor::class)]
    public function run(): string
    {
        return 'value';
    }
}
it('executes source attributes through the core provider without app services or artifacts', function (): void {
    $composition = (new ConfigFactory())->create(
        new Environment([]),
        new ConfigProvider(),
        static fn (): array => [ConfigKey::HTTP_INTERCEPTORS => [AttributeInterceptor::class]]
    );
    $container = (new ContainerFactory())->create($composition->config, $composition->dependencies);
    $pipeline = $container->get(PipelineInterface::class);
    expect($pipeline->handle(new CallableContext([new FactoryWiredTarget(), 'run'])))->toBe('wired:value');
});
