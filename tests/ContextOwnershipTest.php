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

it('does not retain mutable fallback reflectors for magic dispatch', function (): void {
    $target = new class {
        public function __call(string $name, array $args): string
        {
            return $name;
        }
    };
    $context = new CallableContext([$target, 'virtual']);
    $reflector = $context->reflector;
    $reflector->__construct('strlen');

    expect($context->reflector->getName())->toBe('virtual')
        ->and(($context->getCallable())())->toBe('virtual');
});
