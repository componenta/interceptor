<?php

declare(strict_types=1);

use Componenta\Interceptor\CallableContext;
use Componenta\Interceptor\Scope;

describe('CallableContext::scoped()', function () {
    it('tags the context with the given scope attribute', function () {
        $context = CallableContext::scoped(Scope::HTTP, static fn () => null);

        expect($context->getAttribute(CallableContext::SCOPE_ATTRIBUTE))->toBe(Scope::HTTP);
    });

    it('overrides a scope attribute already present in the attributes array', function () {
        $context = CallableContext::scoped(
            Scope::CONSOLE,
            static fn () => null,
            attributes: [CallableContext::SCOPE_ATTRIBUTE => Scope::HTTP],
        );

        expect($context->getAttribute(CallableContext::SCOPE_ATTRIBUTE))->toBe(Scope::CONSOLE);
    });

    it('preserves other attributes passed alongside the scope', function () {
        $marker = new stdClass();

        $context = CallableContext::scoped(
            Scope::HTTP,
            static fn () => null,
            attributes: ['request.id' => 'abc-123', 'payload' => $marker],
        );

        expect($context->getAttribute('request.id'))->toBe('abc-123')
            ->and($context->getAttribute('payload'))->toBe($marker)
            ->and($context->getAttribute(CallableContext::SCOPE_ATTRIBUTE))->toBe(Scope::HTTP);
    });

    it('forwards parameters to the resulting context', function () {
        $context = CallableContext::scoped(
            Scope::HTTP,
            static fn () => null,
            parameters: ['id' => 42],
        );

        expect($context->parameters)->toBe(['id' => 42]);
    });
});


it('replaces a referenced parameter without changing the source or its aliases', function (string|int $key): void {
    $value = 'original';
    $marker = new stdClass();
    $context = new CallableContext(
        static fn (): null => null,
        [$key => &$value, 'alias' => &$value, 2 => $marker],
    );

    $changed = $context->withParameter($key, 'changed');

    expect($changed->parameters)->toBe([$key => 'changed', 'alias' => 'original', 2 => $marker])
        ->and($context->parameters)->toBe([$key => 'original', 'alias' => 'original', 2 => $marker])
        ->and($value)->toBe('original');
})->with(['named key' => ['value'], 'integer key' => [5], 'numeric string key' => ['5']]);
