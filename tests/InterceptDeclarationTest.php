<?php

declare(strict_types=1);

use Componenta\Interceptor\Attribute\Intercept;
use Componenta\Interceptor\AttributeInterceptor;
use Componenta\Interceptor\CallableContext;
use Componenta\Interceptor\CallableContextInterface;
use Componenta\Interceptor\CallbackInterceptorFactory;
use Componenta\Interceptor\InterceptingExecutor;
use Componenta\Interceptor\Tests\Fixture\FactoryStub;
use Componenta\Interceptor\Tests\Fixture\PassThroughExecutor;

it('captures attribute constructor slots by value without cloning objects', function (): void {
    $value = 'original';
    $object = new stdClass();
    $attribute = new Intercept('app.interceptor', ['value' => &$value, 'object' => $object]);

    $value = 'external';

    expect($attribute->params)->toBe(['value' => 'original', 'object' => $object]);
});

it('rejects an empty factory identifier at the declaration boundary', function (string $id): void {
    expect(fn () => new Intercept($id))->toThrow(InvalidArgumentException::class);
})->with(['', ' ']);

it('resolves an interceptor through an opaque factory service identifier', function (): void {
    $factory = new FactoryStub();
    $factory->bind('app.interceptor', static fn (array $params) => CallbackInterceptorFactory::after(
        static fn (string $result): string => $params['prefix'] . ':' . $result,
    ));
    $target = #[Intercept('app.interceptor', ['prefix' => 'service'])] static fn (): string => 'value';
    $pipeline = new InterceptingExecutor(new PassThroughExecutor(), new AttributeInterceptor($factory));

    expect($pipeline->handle(new CallableContext($target)))->toBe('service:value')
        ->and($factory->calls)->toBe([['app.interceptor', ['prefix' => 'service']]]);
});
