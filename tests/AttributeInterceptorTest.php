<?php

declare(strict_types=1);

use Componenta\Interceptor\AttributeInterceptor;
use Componenta\Interceptor\CallableContext;
use Componenta\Interceptor\ContextHandler;
use Componenta\Interceptor\Scope;
use Componenta\Interceptor\Tests\Fixture\FactoryStub;
use Componenta\Interceptor\Tests\Fixture\PassThroughExecutor;
use Componenta\Interceptor\Tests\Fixture\RecordingInterceptor;
use Componenta\Interceptor\Tests\Fixture\ScopedRecordingInterceptor;
use Componenta\Interceptor\Tests\Fixture\TargetClass;

require_once __DIR__ . '/Fixture/target_function.php';

/**
 * Builds a factory whose RecordingInterceptor / ScopedRecordingInterceptor
 * producers share the same log buffer.
 *
 * @param array<int, string> $log
 */
function attributeFactory(array &$log): FactoryStub
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

function methodContext(string $method, array $attributes = []): CallableContext
{
    $target = new TargetClass();

    return new CallableContext([$target, $method], [], $attributes);
}

function terminal(): ContextHandler
{
    return new ContextHandler(new PassThroughExecutor());
}

describe('intercept()', function () {
    it('invokes the terminal directly when the target method has no interceptor attributes', function () {
        $log = [];
        $interceptor = new AttributeInterceptor(attributeFactory($log));

        $result = $interceptor->intercept(methodContext('plain'), terminal());

        expect($result)->toBe('plain')
            ->and($log)->toBe([]);
    });

    it('resolves and runs an interceptor declared via #[Intercept]', function () {
        $log = [];
        $interceptor = new AttributeInterceptor(attributeFactory($log));

        $result = $interceptor->intercept(methodContext('single'), terminal());

        expect($result)->toBe('single')
            ->and($log)->toBe(['A:before', 'A:after']);
    });

    it('invokes attribute classes that implement InterceptorInterface directly', function () {
        $log = [];
        $interceptor = new AttributeInterceptor(attributeFactory($log));

        $result = $interceptor->intercept(methodContext('direct'), terminal());

        expect($result)->toBe('wrap:direct');
    });

    it('runs multiple #[Intercept] attributes in declaration order (first declared = outermost)', function () {
        $log = [];
        $interceptor = new AttributeInterceptor(attributeFactory($log));

        $interceptor->intercept(methodContext('multiple'), terminal());

        expect($log)->toBe([
            'A:before',
            'B:before',
            'C:before',
            'C:after',
            'B:after',
            'A:after',
        ]);
    });

    it('runs multiple direct-attribute interceptors in declaration order', function () {
        $log = [];
        $interceptor = new AttributeInterceptor(attributeFactory($log));

        $result = $interceptor->intercept(methodContext('directMultiple'), terminal());

        expect($result)->toBe('outer:inner:value');
    });

    it('passes a closure callable through to the terminal without crashing', function () {
        $log = [];
        $factory = attributeFactory($log);
        $interceptor = new AttributeInterceptor($factory);

        $closure = static fn (): string => 'closure-result';

        $result = $interceptor->intercept(new CallableContext($closure), terminal());

        expect($result)->toBe('closure-result')
            ->and($factory->calls)->toBe([]);
    });
});

describe('scope filtering', function () {
    it('skips an interceptor whose attribute scope does not include the current scope', function () {
        $log = [];
        $interceptor = new AttributeInterceptor(attributeFactory($log));

        $context = methodContext('attributeScopedHttp', [
            CallableContext::SCOPE_ATTRIBUTE => Scope::CONSOLE,
        ]);

        $result = $interceptor->intercept($context, terminal());

        expect($result)->toBe('attr-scoped')
            ->and($log)->toBe([]);
    });

    it('runs an interceptor whose attribute scope includes the current scope', function () {
        $log = [];
        $interceptor = new AttributeInterceptor(attributeFactory($log));

        $context = methodContext('attributeScopedHttp', [
            CallableContext::SCOPE_ATTRIBUTE => Scope::HTTP,
        ]);

        $interceptor->intercept($context, terminal());

        expect($log)->toBe(['A:before', 'A:after']);
    });

    it('skips an interceptor whose instance scope does not include the current scope', function () {
        $log = [];
        $interceptor = new AttributeInterceptor(attributeFactory($log));

        $context = methodContext('instanceScopedHttp', [
            CallableContext::SCOPE_ATTRIBUTE => Scope::CONSOLE,
        ]);

        $interceptor->intercept($context, terminal());

        expect($log)->toBe([]);
    });

    it('runs an interceptor whose instance scope includes the current scope', function () {
        $log = [];
        $interceptor = new AttributeInterceptor(attributeFactory($log));

        $context = methodContext('instanceScopedHttp', [
            CallableContext::SCOPE_ATTRIBUTE => Scope::HTTP,
        ]);

        $interceptor->intercept($context, terminal());

        expect($log)->toBe(['A:before', 'A:after']);
    });

    // Attribute-level scope is HTTP; instance-level scope is CONSOLE.
    // The two scenarios distinguish "attribute wins" from "interceptor wins" - both
    // would otherwise pass with a single combined assertion.
    it('runs the interceptor when the attribute-level scope matches, even if the instance scope would not', function () {
        $log = [];
        $interceptor = new AttributeInterceptor(attributeFactory($log));

        $context = methodContext('attributeOverridesInstance', [
            CallableContext::SCOPE_ATTRIBUTE => Scope::HTTP,
        ]);

        $interceptor->intercept($context, terminal());

        expect($log)->toBe(['A:before', 'A:after']);
    });

    it('skips the interceptor when the attribute-level scope does not match, even if the instance scope would', function () {
        $log = [];
        $interceptor = new AttributeInterceptor(attributeFactory($log));

        $context = methodContext('attributeOverridesInstance', [
            CallableContext::SCOPE_ATTRIBUTE => Scope::CONSOLE,
        ]);

        $interceptor->intercept($context, terminal());

        expect($log)->toBe([]);
    });

    it('applies all interceptors when the context has no scope attribute', function () {
        $log = [];
        $interceptor = new AttributeInterceptor(attributeFactory($log));

        $interceptor->intercept(methodContext('attributeScopedHttp'), terminal());

        expect($log)->toBe(['A:before', 'A:after']);
    });

    it('runs a non-scoped interceptor regardless of the scope set on the context', function () {
        // Guards the "no scope restriction declared -> always match" branch.
        // The method uses a non-scoped attribute pointing to a non-scoped interceptor,
        // while the context carries a scope that would otherwise filter things.
        $log = [];
        $interceptor = new AttributeInterceptor(attributeFactory($log));

        $context = methodContext('single', [
            CallableContext::SCOPE_ATTRIBUTE => Scope::HTTP,
        ]);

        $interceptor->intercept($context, terminal());

        expect($log)->toBe(['A:before', 'A:after']);
    });
});

describe('caching', function () {
    it('reuses the same factory-built interceptor across repeated invocations of the same method', function () {
        $log = [];
        $factory = attributeFactory($log);
        $interceptor = new AttributeInterceptor($factory);

        // Fresh terminal each call exercises the "candidates cached, chain rebuilt per terminal" path.
        $interceptor->intercept(methodContext('single'), terminal());
        $interceptor->intercept(methodContext('single'), terminal());
        $interceptor->intercept(methodContext('single'), terminal());

        // Factory was called once (caching), but the interceptor ran three times.
        expect($factory->calls)->toHaveCount(1)
            ->and($factory->calls[0][0])->toBe(RecordingInterceptor::class)
            ->and($log)->toBe([
                'A:before', 'A:after',
                'A:before', 'A:after',
                'A:before', 'A:after',
            ]);
    });

    it('reuses the same composed chain for repeated invocations with the same terminal', function () {
        // Guards the "per-terminal chain cache hit" branch:
        // when the same terminal is used, the composed chain is served from the
        // per-terminal cache instead of being rebuilt.
        $log = [];
        $factory = attributeFactory($log);
        $interceptor = new AttributeInterceptor($factory);
        $fixedTerminal = terminal();

        $interceptor->intercept(methodContext('single'), $fixedTerminal);
        $interceptor->intercept(methodContext('single'), $fixedTerminal);
        $interceptor->intercept(methodContext('single'), $fixedTerminal);

        expect($factory->calls)->toHaveCount(1)
            ->and($log)->toBe([
                'A:before', 'A:after',
                'A:before', 'A:after',
                'A:before', 'A:after',
            ]);
    });

    it('does not consult the factory when the target method has no attributes', function () {
        $log = [];
        $factory = attributeFactory($log);
        $interceptor = new AttributeInterceptor($factory);

        $interceptor->intercept(methodContext('plain'), terminal());
        $interceptor->intercept(methodContext('plain'), terminal());

        expect($factory->calls)->toBe([]);
    });

    it('caches attribute resolution for named functions, not only for methods', function () {
        // Named functions reach the dedicated signatureKey branch - method callables
        // go through a different one. This guards that cache path.
        $log = [];
        $factory = attributeFactory($log);
        $interceptor = new AttributeInterceptor($factory);
        $fn = 'Componenta\\Interceptor\\Tests\\Fixture\\targetFunction';

        $interceptor->intercept(new CallableContext($fn), terminal());
        $interceptor->intercept(new CallableContext($fn), terminal());

        expect($factory->calls)->toHaveCount(1)
            ->and($log)->toBe([
                'F:before', 'F:after',
                'F:before', 'F:after',
            ]);
    });
});
