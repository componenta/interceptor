<?php

declare(strict_types=1);

use Componenta\Interceptor\CallableContext;
use Componenta\Interceptor\CallableContextInterface;
use Componenta\Interceptor\ContextHandlerInterface;
use Componenta\Interceptor\InterceptingExecutor;
use Componenta\Interceptor\InterceptorInterface;
use Componenta\Interceptor\Tests\Fixture\PassThroughExecutor;
use Componenta\Interceptor\Tests\Fixture\RecordingInterceptor;

function intercepting(InterceptorInterface ...$interceptors): InterceptingExecutor
{
    return new InterceptingExecutor(new PassThroughExecutor(), ...$interceptors);
}

describe('call()', function () {
    it('invokes the callable and returns its result when no interceptors are registered', function () {
        $result = intercepting()->call(static fn (): string => 'ok');

        expect($result)->toBe('ok');
    });

    it('passes positional parameters to the callable', function () {
        $result = intercepting()->call(static fn (int $a, int $b): int => $a + $b, [3, 4]);

        expect($result)->toBe(7);
    });

    it('runs registered interceptors in registration order around the callable', function () {
        $log = [];

        $result = intercepting(
            new RecordingInterceptor('A', $log),
            new RecordingInterceptor('B', $log),
            new RecordingInterceptor('C', $log),
        )->call(static function () use (&$log): string {
            $log[] = 'callable';
            return 'value';
        });

        expect($result)->toBe('value')
            ->and($log)->toBe([
                'A:before',
                'B:before',
                'C:before',
                'callable',
                'C:after',
                'B:after',
                'A:after',
            ]);
    });
});

describe('pipe()', function () {
    it('returns a new instance and leaves the original pipeline unchanged', function () {
        $log = [];

        $original = intercepting();
        $extended = $original->pipe(new RecordingInterceptor('X', $log));

        expect($extended)->not->toBe($original);

        $original->call(static fn (): string => 'orig');

        expect($log)->toBe([]);

        $extended->call(static fn (): string => 'ext');

        expect($log)->toBe(['X:before', 'X:after']);
    });

    it('appends interceptors when called multiple times', function () {
        $log = [];

        intercepting()
            ->pipe(new RecordingInterceptor('A', $log))
            ->pipe(new RecordingInterceptor('B', $log))
            ->call(static fn (): string => 'ok');

        expect($log)->toBe(['A:before', 'B:before', 'B:after', 'A:after']);
    });

    it('accepts multiple interceptors in a single call', function () {
        $log = [];

        intercepting()
            ->pipe(
                new RecordingInterceptor('A', $log),
                new RecordingInterceptor('B', $log),
            )
            ->call(static fn (): string => 'ok');

        expect($log)->toBe(['A:before', 'B:before', 'B:after', 'A:after']);
    });
});

describe('short-circuit', function () {
    it('returns the value from an interceptor that does not delegate downstream', function () {
        $log = [];

        $shortCircuit = new class implements InterceptorInterface {
            public function intercept(CallableContextInterface $context, ContextHandlerInterface $handler): mixed
            {
                return 'short-circuited';
            }
        };

        $result = intercepting(
            $shortCircuit,
            new RecordingInterceptor('downstream', $log),
        )->call(static function () use (&$log): string {
            $log[] = 'callable';
            return 'never';
        });

        expect($result)->toBe('short-circuited')
            ->and($log)->toBe([]);
    });
});

describe('handle()', function () {
    it('routes a pre-built context through the chain', function () {
        $log = [];

        $context = new CallableContext(
            static function () use (&$log): string {
                $log[] = 'callable';
                return 'value';
            },
        );

        $result = intercepting(new RecordingInterceptor('A', $log))->handle($context);

        expect($result)->toBe('value')
            ->and($log)->toBe(['A:before', 'callable', 'A:after']);
    });
});

describe('intercept() - nested usage', function () {
    it('runs its own pipeline rooted at the supplied terminal handler', function () {
        $log = [];

        $inner = intercepting(new RecordingInterceptor('inner', $log));

        $outer = intercepting(
            new RecordingInterceptor('outer', $log),
            $inner,
        );

        $outer->call(static function () use (&$log): string {
            $log[] = 'callable';
            return 'ok';
        });

        expect($log)->toBe([
            'outer:before',
            'inner:before',
            'callable',
            'inner:after',
            'outer:after',
        ]);
    });
});
