<?php

declare(strict_types=1);
use Componenta\Config\ConfigFactory;
use Componenta\Config\Environment;
use Componenta\DI\ContainerFactory;
use Componenta\Interceptor\AttributeInterceptor;
use Componenta\Interceptor\ConfigKey;
use Componenta\Interceptor\ConfigProvider;
use Componenta\Interceptor\PipelineInterface;

final class MagicInterceptionTarget
{
    public function __call(string $name, array $arguments): array
    {
        return [$name, $arguments];
    }
}
it('preserves native magic callable argument binding through the configured pipeline', function (): void {
    $composition = (new ConfigFactory())->create(
        new Environment([]),
        new ConfigProvider(),
        static fn (): array => [ConfigKey::HTTP_INTERCEPTORS => [AttributeInterceptor::class]]
    );
    $container = (new ContainerFactory())->create($composition->config, $composition->dependencies);
    expect($container->get(PipelineInterface::class)->call([new MagicInterceptionTarget(), 'missing'], ['named' => 'value']))
        ->toBe(['missing', ['named' => 'value']]);
});
