<?php

declare(strict_types=1);

use Componenta\Interceptor\CallableContext;
use Componenta\Interceptor\CallableContextInterface;
use Componenta\Interceptor\ChainComposer;
use Componenta\Interceptor\ContextHandler;
use Componenta\Interceptor\ContextHandlerInterface;
use Componenta\Interceptor\InterceptorInterface;
use Componenta\Interceptor\Tests\Fixture\PassThroughExecutor;
use Componenta\Interceptor\Tests\Fixture\RecordingInterceptor;

function chainTerminal(): ContextHandler
{
    return new ContextHandler(new PassThroughExecutor());
}

function callableContext(callable $callable): CallableContextInterface
{
    return new CallableContext($callable);
}

describe('compose()', function () {
    it('returns the terminal handler unchanged when the interceptor list is empty', function () {
        $terminal = chainTerminal();

        $chain = ChainComposer::compose([], $terminal);

        expect($chain)->toBe($terminal);
    });

    it('composes interceptors so the first one wraps the chain outermost (FIFO)', function () {
        $log = [];

        $chain = ChainComposer::compose(
            [
                new RecordingInterceptor('A', $log),
                new RecordingInterceptor('B', $log),
                new RecordingInterceptor('C', $log),
            ],
            chainTerminal(),
        );

        $context = callableContext(static function () use (&$log): string {
            $log[] = 'callable';
            return 'value';
        });

        $result = $chain->handle($context);

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

describe('compose() with weakInnermost', function () {
    it('does not strongly retain the terminal - the chain alone cannot keep it alive', function () {
        $noop = new class implements InterceptorInterface {
            public function intercept(CallableContextInterface $context, ContextHandlerInterface $handler): mixed
            {
                return $handler->handle($context);
            }
        };

        $terminal = chainTerminal();
        $terminalRef = WeakReference::create($terminal);

        $chain = ChainComposer::compose([$noop], $terminal, weakInnermost: true);

        unset($terminal);
        gc_collect_cycles();

        // If compose() had used a strong reference, $chain would keep $terminal alive
        // via $chain->next chain, and $terminalRef->get() would still return the terminal.
        // With WeakTerminalHandler at the innermost link, the chain references the
        // terminal weakly and PHP can collect it once external references are gone.
        expect($terminalRef->get())->toBeNull();
    });
});
