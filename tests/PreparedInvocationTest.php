<?php

declare(strict_types=1);

use Componenta\Config\ConfigFactory;
use Componenta\Config\ContainerValue;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\Capability\ValueTransformer;
use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\ContainerFactory;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use Componenta\DI\Resolver\Parameter\ParameterAttributeValue;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\Interceptor\CallableContext;
use Componenta\Interceptor\CallableContextInterface;
use Componenta\Interceptor\CallbackInterceptorFactory;
use Componenta\Interceptor\ConfigKey;
use Componenta\Interceptor\ConfigProvider;
use Componenta\Interceptor\InterceptingExecutor;
use Componenta\Interceptor\InterceptorInterface;
use Componenta\Interceptor\PipelineInterface;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class InvocationSuffix
{
}

final class InvocationSuffixHandler implements ParameterAttributeHandlerInterface
{
    public function resolveParameter(
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
        ParameterAttributeValue $value,
    ): ParameterAttributeValue {
        if (!$value->resolved || !is_string($value->value)) {
            throw new LogicException('Expected a string to transform.');
        }

        return ParameterAttributeValue::resolved($value->value . '!');
    }
}

function preparedInvocationContainer(InterceptorInterface ...$interceptors): ContainerValue
{
    $composition = (new ConfigFactory())->create(
        new Environment([]),
        new ConfigProvider(),
        static fn (): array => [
            ConfigKey::HTTP_INTERCEPTORS => $interceptors,
            ConfigKey::DEPENDENCIES => [
                ConfigKey::ATTRIBUTE_DEFINITIONS => [
                    new AttributeDefinition(
                        InvocationSuffix::class,
                        new InvocationSuffixHandler(),
                        [ValueTransformer::class],
                    ),
                ],
            ],
        ],
    );

    return (new ContainerFactory())->create($composition->config, $composition->dependencies);
}

it('transforms parameters once before interception and once per subsequent invocation', function (): void {
    $seen = [];
    $container = preparedInvocationContainer(CallbackInterceptorFactory::before(
        static function (CallableContextInterface $context) use (&$seen): null {
            $seen[] = $context->parameters;
            return null;
        },
    ));
    $pipeline = $container->get(PipelineInterface::class);
    $executor = $container->get(CallableExecutorInterface::class);
    $plain = new InterceptingExecutor($executor);
    $callable = static fn (#[InvocationSuffix] string $value): string => $value;
    $context = new CallableContext($callable, ['value' => 'value']);

    expect($executor->call($callable, ['value' => 'value']))->toBe('value!')
        ->and($plain->handle($context))->toBe('value!')
        ->and($pipeline->handle($context))->toBe('value!')
        ->and($pipeline->handle($context))->toBe('value!')
        ->and($seen)->toBe([['value!'], ['value!']])
        ->and($context->parameters)->toBe(['value' => 'value']);
});

it('invokes arguments changed by an interceptor without reapplying their attributes', function (string $update): void {
    $container = preparedInvocationContainer(CallbackInterceptorFactory::before(
        static function (CallableContextInterface $context) use ($update): CallableContextInterface {
            $context = $context->withAttributes(['trace' => 'old'])
                ->withAttribute('trace', 'new')
                ->withoutAttribute('trace');

            return match ($update) {
                'position' => $context->withParameter(0, 'changed'),
                'name' => $context->withParameter('value', 'changed'),
                'replace' => $context->withParameters(['value' => 'changed']),
            };
        },
    ));
    $context = new CallableContext(
        static fn (#[InvocationSuffix] string $value): string => $value,
        ['value' => 'original'],
    );

    expect($container->get(PipelineInterface::class)->handle($context))->toBe('changed');
})->with(['position', 'name', 'replace']);


it('keeps prepared argument transformations single through a nested pipeline', function (): void {
    $container = preparedInvocationContainer();
    $nested = new InterceptingExecutor(
        $container->get(CallableExecutorInterface::class),
        new \Componenta\Interceptor\ParameterResolvingInterceptor(
            $container->get(\Componenta\DI\Resolver\Parameter\ParametersResolverInterface::class, \Componenta\DI\Resolver\Parameter\ParametersResolverInterface::class),
        ),
    );
    $pipeline = $container->get(PipelineInterface::class)->pipe($nested);
    $context = new CallableContext(
        static fn (#[InvocationSuffix] string $value, string ...$rest): array => [$value, $rest],
        ['value' => 'value', 'rest' => ['named' => 'tail']],
    );

    expect($pipeline->handle($context))->toBe(['value!', ['named' => 'tail']])
        ->and($pipeline->handle($context))->toBe(['value!', ['named' => 'tail']]);
});

it('prepares the new callable after an interceptor replaces the target', function (): void {
    $replacement = static fn (#[InvocationSuffix] string $value): string => 'replacement:' . $value;
    $container = preparedInvocationContainer(CallbackInterceptorFactory::before(
        static fn (CallableContextInterface $context): CallableContextInterface =>
            $context->withCallable($replacement, ['value' => 'next']),
    ));
    $context = new CallableContext(
        static fn (#[InvocationSuffix] string $value): string => 'original:' . $value,
        ['value' => 'original'],
    );

    expect($container->get(PipelineInterface::class)->handle($context))->toBe('replacement:next!');
});

it('propagates a target exception unchanged after preparing its parameters', function (): void {
    $failure = new DomainException('Target failed.');
    $container = preparedInvocationContainer();
    $context = new CallableContext(
        static function (#[InvocationSuffix] string $value) use ($failure): never {
            if ($value !== 'value!') {
                throw new LogicException('The target received an incorrectly transformed value.');
            }
            throw $failure;
        },
        ['value' => 'value'],
    );

    $caught = null;
    try {
        $container->get(PipelineInterface::class)->handle($context);
    } catch (DomainException $actual) {
        $caught = $actual;
    }
    expect($caught)->toBe($failure);
});

#[Attribute(Attribute::TARGET_METHOD)]
final class PreparedInvocationEnvelope implements InterceptorInterface
{
    public function intercept(
        CallableContextInterface $context,
        \Componenta\Interceptor\ContextHandlerInterface $handler,
    ): mixed {
        return $context->getParameter(0) . ':' . $handler->handle($context);
    }
}

final class PreparedInvocationTarget
{
    #[PreparedInvocationEnvelope]
    public function run(#[InvocationSuffix] string $value): string
    {
        return $value;
    }
}

it('preserves method interception after parameter resolution', function (): void {
    $container = preparedInvocationContainer();
    $attributes = new \Componenta\Interceptor\AttributeInterceptor(
        $container->get(\Componenta\DI\FactoryInterface::class),
    );
    $pipeline = $container->get(PipelineInterface::class)->pipe($attributes);
    $context = new CallableContext([new PreparedInvocationTarget(), 'run'], ['value' => 'value']);

    expect($pipeline->handle($context))->toBe('value!:value!')
        ->and($pipeline->handle($context))->toBe('value!:value!');
});


it('uses PHP defaults when an interceptor removes a prepared optional argument', function (string|int $key, bool $named = false): void {
    $container = preparedInvocationContainer(CallbackInterceptorFactory::before(
        static fn (CallableContextInterface $context): CallableContextInterface => ($named ? $context->withParameters(['left' => 'left', 'right' => 'right!']) : $context)->withoutParameter($key),
    ));
    $context = new CallableContext(
        static fn (string $left = 'default', #[InvocationSuffix] string $right = 'right'): string => $left . ':' . $right,
        ['left' => 'left', 'right' => 'right'],
    );

    expect($container->get(PipelineInterface::class)->handle($context))->toBe('default:right!');
})->with(['by name' => ['left'], 'by position' => [0], 'numeric string' => ['0', true]]);


it('preserves positional and named variadic values after removing an optional fixed argument', function (): void {
    $container = preparedInvocationContainer(CallbackInterceptorFactory::before(
        static fn (CallableContextInterface $context): CallableContextInterface => $context->withoutParameter('prefix'),
    ));
    $context = new CallableContext(
        static fn (string $prefix = 'default', string ...$values): array => [$prefix, $values],
        ['prefix' => 'removed', 'values' => ['first', 'named' => 'last']],
    );

    expect($container->get(PipelineInterface::class)->handle($context))
        ->toBe(['default', [0 => 'first', 'named' => 'last']]);
});

it('does not shift variadic values into a required argument removed by an interceptor', function (): void {
    $called = false;
    $container = preparedInvocationContainer(CallbackInterceptorFactory::before(
        static fn (CallableContextInterface $context): CallableContextInterface => $context->withoutParameter('required'),
    ));
    $context = new CallableContext(
        static function (string $required, string ...$values) use (&$called): void {
            $called = true;
        },
        ['required' => 'removed', 'values' => ['tail']],
    );

    expect(fn () => $container->get(PipelineInterface::class)->handle($context))
        ->toThrow(ArgumentCountError::class)
        ->and($called)->toBeFalse();
});

it('keeps named binding and defaults when an interceptor replaces the prepared argument list', function (): void {
    $container = preparedInvocationContainer(CallbackInterceptorFactory::before(
        static fn (CallableContextInterface $context): CallableContextInterface =>
            $context->withParameters(['right' => 'old'])->withParameter(1, 'changed'),
    ));
    $context = new CallableContext(
        static fn (string $left = 'default', #[InvocationSuffix] string $right = 'right'): string => $left . ':' . $right,
        ['left' => 'left', 'right' => 'right'],
    );

    expect($container->get(PipelineInterface::class)->handle($context))->toBe('default:changed');
});

it('preserves explicit nulls when interceptors read parameters and attributes with fallbacks', function (bool $prepared): void {
    $container = preparedInvocationContainer();
    $reader = CallbackInterceptorFactory::before(
        static fn (CallableContextInterface $context): CallableContextInterface => $context->withParameters([
            $context->getParameter(0, 'parameter fallback'),
            $context->getAttribute('label', 'attribute fallback'),
            $context->getParameter('missing', 'absent parameter'),
            $context->getAttribute('missing', 'absent attribute'),
        ]),
    );
    $pipeline = $prepared
        ? $container->get(PipelineInterface::class)->pipe($reader)
        : new InterceptingExecutor($container->get(CallableExecutorInterface::class), $reader);
    $context = new CallableContext(
        static fn (?string $value, ?string $label = null, string ...$missing): array => [$value, $label, $missing],
        [null],
        ['label' => null],
    );

    expect($pipeline->handle($context))->toBe([null, null, ['absent parameter', 'absent attribute']]);
})->with(['source context' => [false], 'prepared context' => [true]]);


it('lets downstream interceptors read the argument updated by name or position', function (array $parameters, string|int $key): void {
    $container = preparedInvocationContainer(CallbackInterceptorFactory::around(
        static fn (CallableContextInterface $context): CallableContextInterface =>
            $context->withParameters($parameters)->withParameter($key, 'changed'),
        static fn (string $result, CallableContextInterface $context): array => [
            $result,
            $context->getParameter($key, 'missing'),
            $context->getParameter('value', 'missing'),
            $context->getParameter(0, 'missing'),
        ],
    ));

    expect($container->get(PipelineInterface::class)->call(
        static fn (string $value = 'default'): string => $value,
    ))->toBe(['changed', 'changed', 'changed', 'changed']);
})->with([
    'named update of positional arguments' => [['original'], 'value'],
    'positional update of named arguments' => [['value' => 'original'], 0],
    'numeric string update of named arguments' => [['value' => 'original'], '0'],
    'named update with both keys' => [[0 => 'positional', 'value' => 'named'], 'value'],
]);


it('preserves a supplied executor pipeline after preparing arguments', function (): void {
    $container = preparedInvocationContainer();
    $resolver = new \Componenta\Interceptor\ParameterResolvingInterceptor(
        $container->get(\Componenta\DI\Resolver\Parameter\ParametersResolverInterface::class, \Componenta\DI\Resolver\Parameter\ParametersResolverInterface::class),
    );
    $inner = new InterceptingExecutor(
        $container->get(CallableExecutorInterface::class),
        $resolver,
        new \Componenta\Interceptor\AttributeInterceptor($container->get(\Componenta\DI\FactoryInterface::class)),
        CallbackInterceptorFactory::after(static fn (string $result): string => 'inner:' . $result),
    );
    $outer = new InterceptingExecutor($inner, $resolver);
    $callable = [new PreparedInvocationTarget(), 'run'];

    expect($inner->call($callable, ['value' => 'value']))->toBe('value!:inner:value!')
        ->and($outer->call($callable, ['value' => 'value']))->toBe('value!:inner:value!')
        ->and($outer->call($callable, ['value' => 'next']))->toBe('next!:inner:next!');
});


final readonly class InvocationDecoratingExecutor implements CallableExecutorInterface
{
    public function __construct(
        private \Componenta\DI\CallableInvokerInterface $invoker,
        private \Componenta\DI\CallableResolverInterface $resolver,
    ) {
    }

    public function resolve(mixed $callable): callable
    {
        return $this->resolver->resolve($callable);
    }

    public function call(mixed $callable, array $params = []): mixed
    {
        return ['executor', $this->invoker->call($this->resolve($callable), $params)];
    }
}

it('preserves executor decoration with DI and native invocation after argument preparation', function (bool $native): void {
    $container = preparedInvocationContainer();
    $executor = new InvocationDecoratingExecutor(
        $native ? new \Componenta\DI\CallableInvoker() : $container->get(CallableExecutorInterface::class),
        $container->get(\Componenta\DI\CallableResolverInterface::class),
    );
    $pipeline = new InterceptingExecutor(
        $executor,
        new \Componenta\Interceptor\ParameterResolvingInterceptor(
            $container->get(\Componenta\DI\Resolver\Parameter\ParametersResolverInterface::class, \Componenta\DI\Resolver\Parameter\ParametersResolverInterface::class),
        ),
    );
    $callable = static fn (#[InvocationSuffix] string $value, string ...$rest): array => [$value, $rest];

    expect($pipeline->call($callable, ['value' => 'first', 'rest' => ['one', 'named' => 'two']]))
        ->toBe(['executor', ['first!', ['one', 'named' => 'two']]])
        ->and($pipeline->call($callable, ['value' => 'next', 'rest' => ['named' => 'three']]))
        ->toBe(['executor', ['next!', ['named' => 'three']]]);
})->with(['DI executor' => [false], 'native invoker' => [true]]);


it('preserves prepared callables across public pipeline entry points', function (string $entry, bool $nested): void {
    $traces = [];
    $container = preparedInvocationContainer(CallbackInterceptorFactory::before(
        static function (CallableContextInterface $context) use (&$traces): null {
            $traces[] = $context->getAttribute('trace');
            return null;
        },
    ));
    $pipeline = $container->get(PipelineInterface::class)->pipe(
        $container->get(\Componenta\Interceptor\AttributeInterceptor::class),
    );
    $callable = [new PreparedInvocationTarget(), 'run'];
    $prepared = new \Componenta\DI\PreparedCallable(
        $nested ? new \Componenta\DI\PreparedCallable($callable) : $callable,
    );
    $parameters = ['value' => 'prepared!'];
    $context = $entry === 'factory'
        ? $container->get(\Componenta\Interceptor\CallableContextFactoryInterface::class)
            ->createContext($prepared, $parameters, ['trace' => 'kept'])
        : new CallableContext($prepared, $parameters, ['trace' => 'kept']);
    $terminal = new InterceptingExecutor(
        $container->get(CallableExecutorInterface::class),
        CallbackInterceptorFactory::after(static fn (string $result): string => 'terminal:' . $result),
    );
    $invoke = static fn (): mixed => match ($entry) {
        'call' => $pipeline->call($prepared, $parameters),
        'handle', 'factory' => $pipeline->handle($context),
        'intercept' => $pipeline->intercept($context, $terminal),
    };

    $expected = $entry === 'intercept' ? 'prepared!:terminal:prepared!' : 'prepared!:prepared!';
    expect($invoke())->toBe($expected)
        ->and($invoke())->toBe($expected)
        ->and($traces)->toBe($entry === 'call' ? [null, null] : ['kept', 'kept'])
        ->and($context->getCallable())->toBe($callable)
        ->and($context->prepared)->toBeTrue()
        ->and($context->parameters)->toBe(['value' => 'prepared!']);
})->with([
    'call' => ['call', false],
    'handle' => ['handle', false],
    'factory' => ['factory', false],
    'intercept' => ['intercept', false],
    'nested call' => ['call', true],
    'nested handle' => ['handle', true],
    'nested factory' => ['factory', true],
    'nested intercept' => ['intercept', true],
]);


it('keeps native argument binding through a prepared pipeline', function (array $arguments, array $expected): void {
    $container = preparedInvocationContainer(CallbackInterceptorFactory::before(
        static fn (CallableContextInterface $context): CallableContextInterface => $context->withAttribute('visited', true),
    ));
    $target = static fn (
        string $first = 'default-first',
        string $second = 'default-second',
        string ...$rest,
    ): array => [$first, $second, $rest];
    $prepared = new \Componenta\DI\PreparedCallable($target);

    expect($container->get(CallableExecutorInterface::class)->call($prepared, $arguments))->toBe($expected)
        ->and($container->get(PipelineInterface::class)->call($prepared, $arguments))->toBe($expected);
})->with([
    'ordinary list' => [['first', 'second'], ['first', 'second', []]],
    'non-zero keys' => [[5 => 'first', 9 => 'second'], ['first', 'second', []]],
    'reversed integer keys' => [[1 => 'first', 0 => 'second'], ['first', 'second', []]],
    'mixed variadic tail' => [[8 => 'first', 2 => 'second', 5 => 'tail', 'named' => 'value'], ['first', 'second', ['tail', 'named' => 'value']]],
    'omitted default' => [['second' => 'second'], ['default-first', 'second', []]],
    'named arguments' => [['second' => 'second', 'first' => 'first'], ['first', 'second', []]],
]);

it('retains native argument binding errors through a prepared pipeline', function (array $arguments, string $message): void {
    $container = preparedInvocationContainer();
    $target = static fn (string $first, string $second): array => [$first, $second];
    $prepared = new \Componenta\DI\PreparedCallable($target);

    expect(fn () => $container->get(CallableExecutorInterface::class)->call($prepared, $arguments))
        ->toThrow(Error::class, $message);
    expect(fn () => $container->get(PipelineInterface::class)->call($prepared, $arguments))
        ->toThrow(Error::class, $message);
})->with([
    'positional after named' => [['first' => 'first', 8 => 'second'], 'Cannot use positional argument after named argument'],
    'duplicate fixed argument' => [[8 => 'first', 'first' => 'duplicate', 'second' => 'second'], 'Named parameter $first overwrites previous argument'],
]);

it('edits native prepared arguments by logical name without shifting their neighbours', function (): void {
    $seen = [];
    $container = preparedInvocationContainer(CallbackInterceptorFactory::before(
        static function (CallableContextInterface $context) use (&$seen): CallableContextInterface {
            $seen = [$context->getParameter('first'), $context->getParameter(1)];
            return $context->withParameter('first', 'changed')->withoutParameter('second');
        },
    ));
    $target = static fn (
        string $first = 'default-first',
        string $second = 'default-second',
        string ...$rest,
    ): array => [$first, $second, $rest];

    expect($container->get(PipelineInterface::class)->call(
        new \Componenta\DI\PreparedCallable($target),
        [9 => 'first', 3 => 'second', 5 => 'tail'],
    ))->toBe(['changed', 'default-second', ['tail']])
        ->and($seen)->toBe(['first', 'second']);
});


it('keeps referenced input state isolated across repeated intercepted calls', function (bool $prepared): void {
    $container = preparedInvocationContainer(CallbackInterceptorFactory::around(
        static fn (CallableContextInterface $context): CallableContextInterface => $context
            ->withParameter('value', $context->getParameter('value') . '!')
            ->withAttribute('trace', $context->getAttribute('trace') . '!'),
        static fn (string $result, CallableContextInterface $context): array => [
            $result,
            $context->getAttribute('trace'),
            $context->getAttribute('alias'),
        ],
    ));
    $value = 'original';
    $trace = 'trace';
    $marker = new stdClass();
    $target = static fn (string $value): string => $value;
    $context = new CallableContext(
        $prepared ? new \Componenta\DI\PreparedCallable($target) : $target,
        ['value' => &$value],
        ['trace' => &$trace, 'alias' => &$trace, 'marker' => $marker],
    );
    $pipeline = $container->get(PipelineInterface::class);

    expect($pipeline->handle($context))->toBe(['original!', 'trace!', 'trace'])
        ->and($pipeline->handle($context))->toBe(['original!', 'trace!', 'trace'])
        ->and($context->parameters)->toBe(['value' => 'original'])
        ->and($context->attributes)->toBe(['trace' => 'trace', 'alias' => 'trace', 'marker' => $marker])
        ->and($value)->toBe('original')
        ->and($trace)->toBe('trace');
})->with(['DI arguments' => [false], 'native prepared arguments' => [true]]);

it('binds a positional variadic value added after named variadic arguments', function (): void {
    $container = preparedInvocationContainer(CallbackInterceptorFactory::before(
        static fn (CallableContextInterface $context): CallableContextInterface => $context->withParameter(1, 'tail'),
    ));
    $target = static fn (string $first, string ...$rest): array => [$first, $rest];
    $prepared = new \Componenta\DI\PreparedCallable($target);

    expect($container->get(PipelineInterface::class)->handle(
        new CallableContext($prepared, ['first' => 'value', 'named' => 'last']),
    ))->toBe(['value', ['tail', 'named' => 'last']]);
});

it('recognizes an already prepared replacement target inside the chain', function (): void {
    $replacement = new PreparedInvocationTarget();
    $container = preparedInvocationContainer(
        CallbackInterceptorFactory::before(
            static fn (CallableContextInterface $context): CallableContextInterface =>
                $context->withCallable(new \Componenta\DI\PreparedCallable([$replacement, 'run']), ['value' => 'ready']),
        ),
    );
    $pipeline = $container->get(PipelineInterface::class)
        ->pipe($container->get(\Componenta\Interceptor\AttributeInterceptor::class));

    expect($pipeline->call(static fn (): string => 'original'))->toBe('ready:ready');
});

it('lets a context explicitly mark a complete invocation vector as prepared', function (): void {
    $container = preparedInvocationContainer();
    $original = new CallableContext(static fn (#[InvocationSuffix] string $value): string => $value, ['value' => 'input']);
    $prepared = $original->withPreparedParameters(['value' => 'ready']);

    expect($original->prepared)->toBeFalse()
        ->and($prepared->prepared)->toBeTrue()
        ->and($container->get(PipelineInterface::class)->handle($original))->toBe('input!')
        ->and($container->get(PipelineInterface::class)->handle($prepared))->toBe('ready');
});

it('exposes only the effective fixed argument after editing duplicate logical aliases', function (string|int $key): void {
    $container = preparedInvocationContainer(CallbackInterceptorFactory::around(
        static fn (CallableContextInterface $context): CallableContextInterface =>
            $context->withParameters([0 => 'stale', 'value' => 'selected'])->withParameter($key, 'changed'),
        static fn (string $result, CallableContextInterface $context): array => [
            $result,
            $context->parameters,
            $context->getParameter(0),
            $context->getParameter('value'),
        ],
    ));

    expect($container->get(PipelineInterface::class)->call(static fn (string $value = 'default'): string => $value))
        ->toBe(['changed', ['value' => 'changed'], 'changed', 'changed']);
})->with(['name' => ['value'], 'position' => [0]]);

it('keeps native duplicate-argument errors before logical edits', function (): void {
    $container = preparedInvocationContainer();
    $target = new \Componenta\DI\PreparedCallable(static fn (string $value): string => $value);
    $context = new CallableContext($target, [0 => 'positional', 'value' => 'named']);

    expect(fn () => $container->get(PipelineInterface::class)->handle($context))->toThrow(Error::class)
        ->and($container->get(PipelineInterface::class)->handle($context->withParameter('value', 'edited')))->toBe('edited');
});

it('honors an alternative callable context implementation throughout preparation and interception', function (bool $prepared): void {
    $container = preparedInvocationContainer();
    $pipeline = $container->get(PipelineInterface::class, PipelineInterface::class)
        ->pipe($container->get(\Componenta\Interceptor\AttributeInterceptor::class));
    $original = new \Componenta\Interceptor\Tests\Fixture\DelegatingContext(
        new CallableContext([new PreparedInvocationTarget(), 'run'], ['value' => 'input']),
    );
    $context = $prepared ? $original->withPreparedParameters(['value' => 'ready']) : $original;
    $expected = $prepared ? 'ready:ready' : 'input!:input!';

    expect($pipeline->handle($context))->toBe($expected)
        ->and($pipeline->handle($context))->toBe($expected)
        ->and($original->prepared)->toBeFalse()
        ->and($original->parameters)->toBe(['value' => 'input']);
})->with(['requires DI preparation' => [false], 'already prepared' => [true]]);


it('inherits logical prepared argument positions when replacing the target with a prepared callable', function (string $entry): void {
    $original = static fn (string $left = 'default', string $right = 'original'): array => [$left, $right];
    $replacement = static fn (string $left = 'new-default', string $right = 'original'): array => ['replacement', $left, $right];
    $pipeline = preparedInvocationContainer()->get(PipelineInterface::class, PipelineInterface::class);
    $context = new CallableContext(new \Componenta\DI\PreparedCallable($original), ['removed', 'kept']);
    $edited = $context->withoutParameter(0);

    $result = match ($entry) {
        'direct' => $pipeline->handle($edited->withCallable(new \Componenta\DI\PreparedCallable($replacement))),
        'interceptor' => $pipeline->pipe(CallbackInterceptorFactory::before(
            static fn (CallableContextInterface $context): CallableContextInterface =>
                $context->withoutParameter('left')->withCallable(new \Componenta\DI\PreparedCallable($replacement)),
        ))->handle($context),
    };

    expect($result)->toBe(['replacement', 'new-default', 'kept'])
        ->and($pipeline->handle($edited->withCallable(new \Componenta\DI\PreparedCallable($replacement), ['explicit'])))->toBe(['replacement', 'explicit', 'original'])
        ->and($pipeline->handle($edited))->toBe(['default', 'kept'])
        ->and($pipeline->handle($context))->toBe(['removed', 'kept']);
})->with(['direct', 'interceptor']);


it('adds a missing fixed argument by name or declaration position in a prepared context', function (string|int $key): void {
    $target = static fn (string $first = 'default', string $second = 'other'): array => [$first, $second];
    $pipeline = preparedInvocationContainer()->get(PipelineInterface::class, PipelineInterface::class);
    $context = new CallableContext(new \Componenta\DI\PreparedCallable($target), ['second' => 'kept']);
    $edited = $context->withParameter($key, 'added');

    expect($edited->getParameter('first'))->toBe('added')
        ->and($edited->getParameter(0))->toBe('added')
        ->and($pipeline->handle($edited))->toBe(['added', 'kept'])
        ->and($pipeline->handle($context))->toBe(['default', 'kept']);
})->with(['name' => ['first'], 'position' => [0]]);


it('preserves inherited variadic values when a prepared target is replaced by a DI-resolved target', function (array $arguments, bool $logical, array $expected): void {
    $original = static fn (string $first = 'original-default', string ...$rest): array => [$first, $rest];
    $replacement = static fn (#[InvocationSuffix] string $first = 'replacement-default', string ...$rest): array => [$first, $rest];
    $pipeline = preparedInvocationContainer()->get(PipelineInterface::class, PipelineInterface::class);
    $context = new CallableContext(new \Componenta\DI\PreparedCallable($original), $arguments);
    if ($logical) {
        // The numeric slots describe positions, not a packed invocation list.
        $context = $context->withoutParameter('first');
    }
    $changed = $context->withCallable($replacement);

    expect($changed->prepared)->toBeFalse()
        ->and($pipeline->handle($changed))->toBe($expected)
        ->and($pipeline->handle($changed))->toBe($expected);
})->with([
    'named variadic arguments' => [
        ['first' => 'value', 'named' => 'tail'],
        false,
        ['value!', ['named' => 'tail']],
    ],
    'mixed native variadic arguments' => [
        ['value', 'tail', 'rest' => 'literal-name', 'named' => 'last'],
        false,
        ['value!', ['tail', 'rest' => 'literal-name', 'named' => 'last']],
    ],
    'logical omitted fixed argument' => [
        ['value', 'tail', 'named' => 'last'],
        true,
        ['replacement-default!', ['tail', 'named' => 'last']],
    ],
    'positional control' => [
        ['value', 'tail'],
        false,
        ['value!', ['tail']],
    ],
]);

it('leaves explicitly supplied DI arguments in their supplied format when replacing a prepared target', function (): void {
    $original = static fn (string ...$values): array => $values;
    $replacement = static fn (string ...$values): array => $values;
    $pipeline = preparedInvocationContainer()->get(PipelineInterface::class, PipelineInterface::class);
    $context = new CallableContext(new \Componenta\DI\PreparedCallable($original), ['old', 'named' => 'old']);
    $changed = $context->withCallable($replacement, ['values' => ['new', 'named' => 'new']]);

    expect($pipeline->handle($changed))->toBe(['new', 'named' => 'new']);
});
