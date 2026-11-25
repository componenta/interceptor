<?php

declare(strict_types=1);

namespace Componenta\Interceptor\Benchmarks;

use Attribute;
use Closure;
use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\ContainerFactory;
use Componenta\DI\FactoryInterface;
use Componenta\DI\PreparedCallable;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\Interceptor\Attribute\Intercept;
use Componenta\Interceptor\AttributeInterceptor;
use Componenta\Interceptor\CallableContext;
use Componenta\Interceptor\CallableContextInterface;
use Componenta\Interceptor\CallbackInterceptorFactory;
use Componenta\Interceptor\ContextHandlerInterface;
use Componenta\Interceptor\InterceptingExecutor;
use Componenta\Interceptor\InterceptorInterface;
use Componenta\Interceptor\ParameterResolvingInterceptor;
use Componenta\Reflection\Reflection;
use LogicException;
use PhpBench\Attributes as Bench;

final class ForwardInterceptor implements InterceptorInterface
{
    public function intercept(CallableContextInterface $context, ContextHandlerInterface $handler): mixed
    {
        return $handler->handle($context);
    }
}

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class ForwardAttribute implements InterceptorInterface
{
    public function intercept(CallableContextInterface $context, ContextHandlerInterface $handler): mixed
    {
        return $handler->handle($context);
    }
}

#[Attribute(Attribute::TARGET_METHOD)]
final class CachedResult implements InterceptorInterface
{
    public function intercept(CallableContextInterface $context, ContextHandlerInterface $handler): mixed
    {
        return 7;
    }
}

final class BenchDependency {}

final class BenchTarget
{
    public function plain(int $value): int
    {
        return $value;
    }

    #[ForwardAttribute]
    public function nativeAttribute(int $value): int
    {
        return $value;
    }

    #[Intercept(ForwardInterceptor::class)]
    public function factoryAttribute(int $value): int
    {
        return $value;
    }

    #[ForwardAttribute]
    #[ForwardAttribute]
    #[ForwardAttribute]
    #[ForwardAttribute]
    #[ForwardAttribute]
    public function fiveAttributes(int $value): int
    {
        return $value;
    }

    #[CachedResult]
    #[Intercept(ForwardInterceptor::class)]
    public function shortCircuit(int $value): never
    {
        throw new LogicException('Short-circuited benchmark must not call its target.');
    }

    public function dependency(BenchDependency $dependency, int $value): int
    {
        return $value;
    }

    public function variadic(int $value, int ...$rest): int
    {
        return $value + array_sum($rest);
    }
}

#[Bench\Iterations(7)]
#[Bench\Revs(1000)]
final class InterceptorBench
{
    /** @var Closure(): int */
    private Closure $operation;
    private int $result;

    /** @return iterable<string, array{scenario:string}> */
    public function provideScenarios(): iterable
    {
        foreach ([
            'native', 'di', 'pipeline-0', 'pipeline-1', 'pipeline-5', 'pipeline-10', 'pipeline-50',
            'attribute-none', 'attribute-native', 'attribute-factory', 'attribute-five', 'attribute-short-circuit',
            'nested-pipeline', 'di-preparation', 'di-preparation-edit',
            'prepared-native', 'prepared-edit', 'context-handle', 'context-copy',
        ] as $scenario) {
            yield $scenario => ['scenario' => $scenario];
        }
    }

    /** @param array{scenario:string} $params */
    public function prepare(array $params): void
    {
        $target = new BenchTarget();
        $container = (new ContainerFactory())->create(
            new Config([], new Environment([])),
            new DependencyDefinitions([]),
        );
        $executor = $container->get(CallableExecutorInterface::class, CallableExecutorInterface::class);
        $plain = [$target, 'plain'];
        $forward = new ForwardInterceptor();
        $scenario = $params['scenario'];

        if (str_starts_with($scenario, 'pipeline-')) {
            $count = (int) substr($scenario, strlen('pipeline-'));
            $pipeline = new InterceptingExecutor($executor, ...array_fill(0, $count, $forward));
            $this->operation = static fn (): int => $pipeline->call($plain, ['value' => 7]);
        } elseif (str_starts_with($scenario, 'attribute-')) {
            $attributes = new AttributeInterceptor($container->get(FactoryInterface::class, FactoryInterface::class));
            $pipeline = new InterceptingExecutor($executor, $attributes);
            $method = match ($scenario) {
                'attribute-none' => 'plain',
                'attribute-native' => 'nativeAttribute',
                'attribute-factory' => 'factoryAttribute',
                'attribute-five' => 'fiveAttributes',
                'attribute-short-circuit' => 'shortCircuit',
            };
            $this->operation = static fn (): int => $pipeline->call([$target, $method], ['value' => 7]);
        } else {
            $this->operation = match ($scenario) {
                'native' => static fn (): int => $target->plain(7),
                'di' => static fn (): int => $executor->call($plain, ['value' => 7]),
                'nested-pipeline' => $this->nested($executor, $forward, $plain),
                'di-preparation', 'di-preparation-edit' => $this->preparation(
                    $executor,
                    $container->get(ParametersResolver::class, ParametersResolver::class),
                    $target,
                    $scenario === 'di-preparation-edit',
                ),
                'prepared-native', 'prepared-edit' => $this->prepared($executor, $target, $scenario === 'prepared-edit'),
                'context-handle', 'context-copy' => $this->context($executor, $plain, $scenario === 'context-copy'),
            };
        }

        Reflection::clearReflectors();
    }

    /** @param array{scenario:string} $params */
    public function prepareWarm(array $params): void
    {
        $this->prepare($params);
        $this->result = ($this->operation)();
        $this->verify();
    }

    public function verify(): void
    {
        if ($this->result !== 7) {
            throw new LogicException('Benchmark returned an unexpected result.');
        }
    }

    #[Bench\ParamProviders('provideScenarios')]
    #[Bench\BeforeMethods('prepare')]
    #[Bench\AfterMethods('verify')]
    #[Bench\Revs(1)]
    #[Bench\Iterations(15)]
    public function benchFirstInvocation(): void
    {
        $this->result = ($this->operation)();
    }

    #[Bench\ParamProviders('provideScenarios')]
    #[Bench\BeforeMethods('prepareWarm')]
    #[Bench\AfterMethods('verify')]
    #[Bench\Warmup(100)]
    public function benchRepeatedInvocation(): void
    {
        $this->result = ($this->operation)();
    }

    /** @return Closure(): int */
    private function nested(CallableExecutorInterface $executor, InterceptorInterface $forward, callable $target): Closure
    {
        $inner = new InterceptingExecutor($executor, $forward, $forward, $forward);
        $pipeline = new InterceptingExecutor($executor, $forward, $inner, $forward);

        return static fn (): int => $pipeline->call($target, ['value' => 7]);
    }

    /** @return Closure(): int */
    private function preparation(
        CallableExecutorInterface $executor,
        ParametersResolver $resolver,
        BenchTarget $target,
        bool $edit,
    ): Closure {
        $pipeline = new InterceptingExecutor($executor, new ParameterResolvingInterceptor($resolver->resolve(...)));
        if ($edit) {
            $pipeline = $pipeline->pipe(CallbackInterceptorFactory::before(
                static fn (CallableContextInterface $context): CallableContextInterface => $context->withParameter('value', 7),
            ));
        }
        return static fn (): int => $pipeline->call([$target, 'dependency'], ['value' => $edit ? 5 : 7]);
    }

    /** @return Closure(): int */
    private function prepared(CallableExecutorInterface $executor, BenchTarget $target, bool $edit): Closure
    {
        $pipeline = new InterceptingExecutor($executor);
        if ($edit) {
            $pipeline = $pipeline->pipe(CallbackInterceptorFactory::before(
                static fn (CallableContextInterface $context): CallableContextInterface => $context->withParameter('value', 3),
            ));
        }
        $prepared = new PreparedCallable([$target, 'variadic']);
        $arguments = [$edit ? 1 : 3, 2, 'last' => 2];

        return static fn (): int => $pipeline->call($prepared, $arguments);
    }

    /** @return Closure(): int */
    private function context(CallableExecutorInterface $executor, callable $target, bool $copy): Closure
    {
        $pipeline = new InterceptingExecutor($executor);
        $context = new CallableContext($target, ['value' => $copy ? 5 : 7], ['trace' => 'benchmark']);

        return $copy
            ? static fn (): int => $pipeline->handle($context->withParameter('value', 7)->withAttribute('trace', 'updated'))
            : static fn (): int => $pipeline->handle($context);
    }
}
