<?php

declare(strict_types=1);

use Componenta\Interceptor\CallableContext;
use Componenta\Interceptor\CallableContextInterface;
use Componenta\Interceptor\CallbackInterceptorFactory;
use Componenta\Interceptor\ContextHandler;
use Componenta\Interceptor\ContextHandlerInterface;
use Componenta\Interceptor\InterceptorInterface;
use Componenta\Interceptor\Tests\Fixture\PassThroughExecutor;

function runWith(InterceptorInterface $interceptor, callable $callable): mixed
{
    $context = new CallableContext($callable);
    $terminal = new ContextHandler(new PassThroughExecutor());

    return $interceptor->intercept($context, $terminal);
}

/**
 * @param mixed $captured Will be set to the context the downstream handler received.
 */
function captureContextHandler(mixed &$captured): ContextHandlerInterface
{
    return new class($captured) implements ContextHandlerInterface {
        public function __construct(private mixed &$captured) {}
        public function handle(CallableContextInterface $context): mixed
        {
            $this->captured = $context;
            return 'ok';
        }
    };
}

describe('before()', function () {
    it('passes the modified context returned by the callback to the downstream handler', function () {
        $captured = null;

        $beforeAdapter = CallbackInterceptorFactory::before(
            static fn (CallableContextInterface $c): CallableContextInterface
                => $c->withAttribute('marker', 'set-by-before'),
        );

        $beforeAdapter->intercept(
            new CallableContext(static fn (): string => 'x'),
            captureContextHandler($captured),
        );

        expect($captured->getAttribute('marker'))->toBe('set-by-before');
    });

    it('passes the original context through when the callback returns null', function () {
        $captured = null;
        $original = new CallableContext(static fn (): string => 'x');

        CallbackInterceptorFactory::before(
            static fn (CallableContextInterface $c): ?CallableContextInterface => null,
        )->intercept($original, captureContextHandler($captured));

        expect($captured)->toBe($original);
    });
});

describe('after()', function () {
    it('transforms the downstream result through the callback', function () {
        $afterAdapter = CallbackInterceptorFactory::after(
            static fn (mixed $result): string => '[' . $result . ']',
        );

        $result = runWith($afterAdapter, static fn (): string => 'value');

        expect($result)->toBe('[value]');
    });
});

describe('catch()', function () {
    it('returns the callback value when the downstream handler throws', function () {
        $catchAdapter = CallbackInterceptorFactory::catch(
            static fn (\Throwable $e): string => 'caught: ' . $e->getMessage(),
        );

        $result = runWith($catchAdapter, static function (): never {
            throw new \RuntimeException('boom');
        });

        expect($result)->toBe('caught: boom');
    });

    it('returns the downstream result unchanged when no exception is thrown', function () {
        $catchAdapter = CallbackInterceptorFactory::catch(
            static fn (\Throwable $e): string => 'never',
        );

        $result = runWith($catchAdapter, static fn (): string => 'happy');

        expect($result)->toBe('happy');
    });
});

describe('finally()', function () {
    it('runs the callback after a successful invocation', function () {
        $ran = false;

        $finallyAdapter = CallbackInterceptorFactory::finally(
            static function () use (&$ran): void { $ran = true; },
        );

        $result = runWith($finallyAdapter, static fn (): string => 'ok');

        expect($result)->toBe('ok')
            ->and($ran)->toBeTrue();
    });

    it('runs the callback when an exception bubbles up and re-throws the original', function () {
        $ran = false;

        $finallyAdapter = CallbackInterceptorFactory::finally(
            static function () use (&$ran): void { $ran = true; },
        );

        $thrown = null;
        try {
            runWith($finallyAdapter, static function (): never {
                throw new \RuntimeException('bubble');
            });
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        expect($ran)->toBeTrue()
            ->and($thrown?->getMessage())->toBe('bubble');
    });
});

describe('around()', function () {
    it('applies before-modification and transforms the result via after', function () {
        $aroundAdapter = CallbackInterceptorFactory::around(
            before: static fn (CallableContextInterface $c): CallableContextInterface
                => $c->withAttribute('marker', 'around-set'),
            after: static fn (mixed $result, CallableContextInterface $c): string
                => $c->getAttribute('marker') . ':' . $result,
        );

        $result = runWith($aroundAdapter, static fn (): string => 'value');

        expect($result)->toBe('around-set:value');
    });
});
