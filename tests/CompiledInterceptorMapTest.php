<?php

declare(strict_types=1);

use Componenta\Interceptor\AttributeInterceptor;
use Componenta\Interceptor\CallableContext;
use Componenta\Interceptor\ContextHandler;
use Componenta\Interceptor\Tests\Fixture\Attribute\RecordingIntercept;
use Componenta\Interceptor\Tests\Fixture\Attribute\WrapResultAttribute;
use Componenta\Interceptor\Tests\Fixture\FactoryStub;
use Componenta\Interceptor\Tests\Fixture\PassThroughExecutor;
use Componenta\Interceptor\Tests\Fixture\RecordingInterceptor;
use Componenta\Interceptor\Tests\Fixture\ScopedRecordingInterceptor;
use Componenta\Interceptor\Tests\Fixture\TargetClass;

function compiledInterceptorFactory(array &$log): FactoryStub
{
    $factory = new FactoryStub();

    $factory->bind(RecordingInterceptor::class, function (array $params) use (&$log) {
        return new RecordingInterceptor($params['name'], $log);
    });

    $factory->bind(ScopedRecordingInterceptor::class, function (array $params) use (&$log) {
        return new ScopedRecordingInterceptor($params['name'], $log, $params['scopes']);
    });

    return $factory;
}

function compiledInterceptorContext(string $method): CallableContext
{
    return new CallableContext([new TargetClass(), $method]);
}

function compiledInterceptorTerminal(): ContextHandler
{
    return new ContextHandler(new PassThroughExecutor());
}

describe('compiled interceptor metadata', function () {
    it('uses compiled descriptors before reading runtime attributes', function () {
        $log = [];
        $interceptor = new AttributeInterceptor(
            compiledInterceptorFactory($log),
            [
                TargetClass::class . '::plain' => [[
                    'kind' => 'direct',
                    'class' => WrapResultAttribute::class,
                    'arguments' => ['marker' => 'compiled'],
                ]],
            ],
        );

        $result = $interceptor->intercept(
            compiledInterceptorContext('plain'),
            compiledInterceptorTerminal(),
        );

        expect($result)->toBe('compiled:plain');
    });

    it('keeps AttributeInterceptor behavior with a compiler-produced map', function () {
        $log = [];
        $factory = compiledInterceptorFactory($log);
        $interceptor = new AttributeInterceptor(
            $factory,
            [
                TargetClass::class . '::single' => [[
                    'kind' => 'factory',
                    'attribute' => RecordingIntercept::class,
                    'arguments' => ['name' => 'A'],
                ]],
                TargetClass::class . '::direct' => [[
                    'kind' => 'direct',
                    'class' => WrapResultAttribute::class,
                    'arguments' => ['marker' => 'wrap'],
                ]],
            ],
        );

        $single = $interceptor->intercept(compiledInterceptorContext('single'), compiledInterceptorTerminal());
        $direct = $interceptor->intercept(compiledInterceptorContext('direct'), compiledInterceptorTerminal());

        expect($single)->toBe('single')
            ->and($direct)->toBe('wrap:direct')
            ->and($factory->calls)->toHaveCount(1)
            ->and($log)->toBe(['A:before', 'A:after']);
    });
});
