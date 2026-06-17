<?php

declare(strict_types=1);

use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\Interceptor\CallableContext;
use Componenta\Interceptor\ContextHandler;
use Componenta\Interceptor\ParameterResolvingInterceptor;
use Componenta\Interceptor\Tests\Fixture\PassThroughExecutor;
use Componenta\Interceptor\Tests\Fixture\SpyParameterResolver;

function paramResolvingInterceptor(SpyParameterResolver $spy): ParameterResolvingInterceptor
{
    return new ParameterResolvingInterceptor(new ParametersResolver($spy));
}

function paramTerminal(): ContextHandler
{
    return new ContextHandler(new PassThroughExecutor());
}

describe('intercept()', function () {
    it('does not invoke the resolver when the target callable takes no parameters', function () {
        $spy = new SpyParameterResolver();
        $interceptor = paramResolvingInterceptor($spy);
        $context = new CallableContext(static fn (): string => 'value');

        $result = $interceptor->intercept($context, paramTerminal());

        expect($result)->toBe('value')
            ->and($spy->callCount)->toBe(0);
    });

    it('resolves each parameter and forwards the resolved values to the callable', function () {
        $spy = new SpyParameterResolver();
        $interceptor = paramResolvingInterceptor($spy);
        $context = new CallableContext(
            static fn (string $foo, string $bar): string => "{$foo}|{$bar}",
        );

        $result = $interceptor->intercept($context, paramTerminal());

        expect($result)->toBe('resolved-foo|resolved-bar')
            ->and($spy->callCount)->toBe(2);
    });
});
