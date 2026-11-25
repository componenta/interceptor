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

it('keeps logical positional edits before named values on native magic dispatch', function (bool $prepared): void {
    $composition = (new ConfigFactory())->create(
        new Environment([]),
        new ConfigProvider(),
    );
    $container = (new ContainerFactory())->create($composition->config, $composition->dependencies);
    $pipeline = $container->get(PipelineInterface::class, PipelineInterface::class);
    $target = [new MagicInterceptionTarget(), 'missing'];
    $context = new \Componenta\Interceptor\CallableContext(
        $prepared ? new \Componenta\DI\PreparedCallable($target) : $target,
        ['named' => 'last'],
    );
    $pipeline = $pipeline->pipe(
        \Componenta\Interceptor\CallbackInterceptorFactory::before(
            static fn (\Componenta\Interceptor\CallableContextInterface $context): \Componenta\Interceptor\CallableContextInterface =>
                $context->withParameter(0, 'first')->withParameter(2, 'second'),
        ),
    );

    expect($pipeline->handle($context))->toBe(['missing', ['first', 'second', 'named' => 'last']])
        ->and($context->parameters)->toBe(['named' => 'last']);
})->with(['DI preparation' => [false], 'prepared invocation' => [true]]);

it('does not silently repair an untouched invalid native magic argument vector', function (): void {
    $composition = (new ConfigFactory())->create(new Environment([]), new ConfigProvider());
    $container = (new ContainerFactory())->create($composition->config, $composition->dependencies);
    $pipeline = $container->get(PipelineInterface::class, PipelineInterface::class);
    $context = new \Componenta\Interceptor\CallableContext(
        new \Componenta\DI\PreparedCallable([new MagicInterceptionTarget(), 'missing']),
        ['named' => 'last', 0 => 'first'],
    );

    expect(fn () => $pipeline->handle($context))->toThrow(Error::class);
});


it('preserves logical magic arguments when replacing the target with an ordinary magic callable', function (): void {
    $composition = (new ConfigFactory())->create(new Environment([]), new ConfigProvider());
    $container = (new ContainerFactory())->create($composition->config, $composition->dependencies);
    $pipeline = $container->get(PipelineInterface::class, PipelineInterface::class);
    $target = new MagicInterceptionTarget();
    $context = new \Componenta\Interceptor\CallableContext(
        new \Componenta\DI\PreparedCallable([$target, 'original']),
        ['named' => 'last'],
    );
    $edited = $context->withParameter(0, 'first');
    $replacement = $edited->withCallable([$target, 'replacement']);

    expect($pipeline->handle($replacement))->toBe(['replacement', ['first', 'named' => 'last']])
        ->and($pipeline->handle($edited))->toBe(['original', ['first', 'named' => 'last']]);
});
