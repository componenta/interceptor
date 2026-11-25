<?php

declare(strict_types=1);

use Componenta\Interceptor\CallableContext;

final class OwnershipFirstTarget
{
    public function run(): string
    {
        return 'first';
    }
}

final class OwnershipSecondTarget
{
    public function run(): string
    {
        return 'second';
    }
}

it('captures callable array slots independently of caller references', function (): void {
    $target = new OwnershipFirstTarget();
    $method = 'run';
    $context = new CallableContext([&$target, &$method]);
    $target = new OwnershipSecondTarget();
    $method = 'missing';
    $copy = $context->getCallable();
    $copy[0] = $target;

    expect(($context->getCallable())())->toBe('first')
        ->and($context->reflector->getDeclaringClass()->getName())->toBe(OwnershipFirstTarget::class);
});

it('captures argument and attribute slots while preserving object identity', function (): void {
    $parameter = 'original';
    $attribute = 'trace';
    $object = new stdClass();
    $context = new CallableContext(
        static fn (): null => null,
        [5 => &$parameter, 'object' => $object],
        ['trace' => &$attribute],
    );

    $parameter = 'external';
    $attribute = 'external';
    $parameters = $context->parameters;
    $attributes = $context->attributes;
    $parameters[5] = 'outgoing';
    $attributes['trace'] = 'outgoing';

    expect($context->parameters)->toBe([5 => 'original', 'object' => $object])
        ->and($context->attributes)->toBe(['trace' => 'trace'])
        ->and($context->getParameter('object'))->toBe($object)
        ->and($parameter)->toBe('external')
        ->and($attribute)->toBe('external');
});

it('captures reference slots when replacing arguments and attributes', function (): void {
    $value = 'original';
    $original = new CallableContext(static fn (): null => null);
    $context = $original->withParameters(['value' => &$value])
        ->withAttributes(['trace' => &$value]);
    $value = 'changed';

    expect($context->getParameter('value'))->toBe('original')
        ->and($context->getAttribute('trace'))->toBe('original')
        ->and($original->parameters)->toBe([])
        ->and($original->attributes)->toBe([]);
});

it('does not let a reflector change the target metadata of a context or later context', function (): void {
    $context = new CallableContext([new OwnershipFirstTarget(), 'run']);
    $reflector = $context->reflector;

    expect(fn () => $reflector->__construct(OwnershipSecondTarget::class, 'run'))
        ->toThrow(LogicException::class);

    expect($context->reflector->getDeclaringClass()->getName())->toBe(OwnershipFirstTarget::class)
        ->and((new CallableContext([new OwnershipFirstTarget(), 'run']))->reflector->getDeclaringClass()->getName())
        ->toBe(OwnershipFirstTarget::class);
});

it('does not invent a concrete declaration for native magic dispatch', function (): void {
    $target = new class {
        public function __call(string $name, array $args): string
        {
            return $name;
        }
    };
    $context = new CallableContext([$target, 'virtual']);
    expect($context->reflector)->toBeNull()
        ->and(($context->getCallable())())->toBe('virtual');
});

final class OwnershipAutoloadTarget
{
    public static function run(string $value): string
    {
        return $value;
    }
}

it('captures arguments and attributes before callable validation runs application autoloaders', function (): void {
    $name = 'OwnershipPending_' . bin2hex(random_bytes(5));
    $owner = $name;
    $argument = 'original';
    $trace = 'trace';
    $loader = static function (string $class) use ($name, &$owner, &$argument, &$trace): void {
        if ($class === $name) {
            class_alias(OwnershipAutoloadTarget::class, $class);
            $owner = OwnershipSecondTarget::class;
            $argument = 'external';
            $trace = 'external';
        }
    };
    spl_autoload_register($loader);
    try {
        $context = new CallableContext([&$owner, 'run'], ['value' => &$argument], ['trace' => &$trace]);

        expect(($context->getCallable())($context->getParameter('value')))->toBe('original')
            ->and($context->getAttribute('trace'))->toBe('trace')
            ->and($owner)->toBe(OwnershipSecondTarget::class)
            ->and($argument)->toBe('external');
    } finally {
        spl_autoload_unregister($loader);
    }
});

it('does not grant access to private implementation methods via native callable specifications', function (bool $array): void {
    $context = new CallableContext(static fn (): null => null);
    $target = $array ? [$context, 'resolveReflector'] : CallableContext::class . '::resolveReflector';

    expect(fn () => new CallableContext($target))->toThrow(TypeError::class);
})->with([false, true]);

it('preserves invocation argument slots when a resolver mutates caller-owned references', function (): void {
    $value = 'original';
    $trace = 'trace';
    $resolver = new class(static function () use (&$value, &$trace): void {
        $value = 'external';
        $trace = 'external';
    }) implements \Componenta\DI\CallableExecutorInterface {
        public function __construct(private Closure $mutate) {}

        public function resolve(mixed $callable): callable
        {
            ($this->mutate)();
            return $callable;
        }

        public function call(mixed $callable, array $params = []): mixed
        {
            return $callable(...$params);
        }
    };
    $target = static fn (string $value): string => $value;
    $factory = new \Componenta\Interceptor\CallableContextFactory($resolver);
    $context = $factory->createContext($target, ['value' => &$value], ['trace' => &$trace]);
    expect($context->getParameter('value'))->toBe('original')
        ->and($context->getAttribute('trace'))->toBe('trace');

    $value = 'original';
    $pipeline = new \Componenta\Interceptor\InterceptingExecutor($resolver);
    expect($pipeline->call($target, ['value' => &$value]))->toBe('original')
        ->and($value)->toBe('external');
});
