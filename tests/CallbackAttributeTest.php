<?php

declare(strict_types=1);

namespace Componenta\Interceptor\Tests;

use Componenta\Interceptor\Attribute\After;
use Componenta\Interceptor\Attribute\Intercept;
use Componenta\Interceptor\Attribute\InterceptCallback;
use Componenta\Interceptor\AttributeInterceptor;
use Componenta\Interceptor\CallableContext;
use Componenta\Interceptor\CallableContextInterface;
use Componenta\Interceptor\ContextHandler;
use Componenta\Interceptor\ContextHandlerInterface;
use Componenta\Interceptor\InterceptingExecutor;
use Componenta\Interceptor\Tests\Fixture\DelegatingContext;
use Componenta\Interceptor\Tests\Fixture\FactoryStub;
use Componenta\Interceptor\Tests\Fixture\PassThroughExecutor;
use RuntimeException;
use stdClass;

final class CallbackAttributeTarget
{
    #[After(static function (mixed $result): string { return 'outer:' . $result; })]
    #[InterceptCallback(static function (CallableContextInterface $context, ContextHandlerInterface $next): string {
        return 'around:' . $next->handle($context);
    })]
    #[After(static function (mixed $result): string { return 'inner:' . $result; })]
    public function ordered(): string
    {
        return 'value';
    }

    #[InterceptCallback(static function (): string { return 'cached'; })]
    #[Intercept('unreachable')]
    public function shortCircuit(): never
    {
        throw new RuntimeException('The target must not run.');
    }

    #[After(static function (mixed $result): array {
        static $calls = 0;
        return [++$calls, $result];
    })]
    public function current(string $value): string
    {
        return $value;
    }

    #[After(static function (mixed $result, CallableContextInterface $context, stdClass $service): mixed {
        return $result;
    })]
    public function extraDependency(): string
    {
        return 'value';
    }
}

trait CallbackAttributeTrait
{
    #[After(self::decorate(...))]
    public function inherited(): string
    {
        return 'value';
    }
}

final class FirstCallbackAttributeOwner
{
    use CallbackAttributeTrait;

    private static function decorate(mixed $value): string
    {
        return 'first:' . $value;
    }
}

final class SecondCallbackAttributeOwner
{
    use CallbackAttributeTrait;

    private static function decorate(mixed $value): string
    {
        return 'second:' . $value;
    }
}

function uppercaseCallbackResult(string $result): string
{
    return strtoupper($result);
}

#[After(uppercaseCallbackResult(...))]
function callbackAttributeFunction(): string
{
    return 'function';
}

function callbackAttributePipeline(?FactoryStub $factory = null): InterceptingExecutor
{
    return new InterceptingExecutor(
        new PassThroughExecutor(),
        new AttributeInterceptor($factory ?? new FactoryStub()),
    );
}

it('passes the exact result and public context to an after callback and returns its output', function (): void {
    $context = new DelegatingContext(new CallableContext(static fn (): mixed => null));
    $value = new stdClass();
    $replacement = new stdClass();
    $calls = [];
    $next = new class($value, $calls) implements ContextHandlerInterface {
        public function __construct(private object $value, private array &$calls) {}
        public function handle(CallableContextInterface $context): mixed
        {
            $this->calls[] = ['next', $context];
            return $this->value;
        }
    };
    $attribute = new After(static function (mixed $result, CallableContextInterface $invocation) use (&$calls, $replacement): object {
        $calls[] = ['after', $result, $invocation];
        return $replacement;
    });

    expect($attribute->intercept($context, $next))->toBe($replacement)
        ->and($calls)->toBe([['next', $context], ['after', $value, $context]]);
});

it('does not execute an after callback when the continuation fails', function (): void {
    $failure = new RuntimeException('downstream');
    $called = false;
    $attribute = new After(static function () use (&$called): void { $called = true; });
    $next = new class($failure) implements ContextHandlerInterface {
        public function __construct(private RuntimeException $failure) {}
        public function handle(CallableContextInterface $context): never { throw $this->failure; }
    };

    try {
        $attribute->intercept(new CallableContext(static fn (): mixed => null), $next);
        test()->fail('The continuation failure must propagate.');
    } catch (RuntimeException $actual) {
        expect($actual)->toBe($failure)->and($called)->toBeFalse();
    }
});

it('preserves the callback failure without retrying the successful continuation', function (): void {
    $failure = new RuntimeException('callback');
    $calls = 0;
    $context = new CallableContext(static function () use (&$calls): string { ++$calls; return 'value'; });
    $attribute = new After(static function () use ($failure): never { throw $failure; });

    try {
        $attribute->intercept($context, new ContextHandler(new PassThroughExecutor()));
        test()->fail('The callback failure must propagate.');
    } catch (RuntimeException $actual) {
        expect($actual)->toBe($failure)->and($calls)->toBe(1);
    }
});

it('passes the public context and continuation unchanged to a full callback', function (): void {
    $context = new DelegatingContext(new CallableContext(static fn (): string => 'value'));
    $next = new ContextHandler(new PassThroughExecutor());
    $seen = [];
    $attribute = new InterceptCallback(static function (CallableContextInterface $invocation, ContextHandlerInterface $handler) use (&$seen): mixed {
        $seen = [$invocation, $handler];
        return $handler->handle($invocation);
    });

    expect($attribute->intercept($context, $next))->toBe('value')
        ->and($seen)->toBe([$context, $next]);
});

it('lets a full callback invoke its continuation multiple times with replacement contexts', function (): void {
    $seen = [];
    $context = new CallableContext(static function (string $value) use (&$seen): string { $seen[] = $value; return $value; });
    $attribute = new InterceptCallback(static function (CallableContextInterface $invocation, ContextHandlerInterface $next): array {
        return [
            $next->handle($invocation->withParameters(['first'])),
            $next->handle($invocation->withParameters(['second'])),
        ];
    });

    expect($attribute->intercept($context, new ContextHandler(new PassThroughExecutor())))->toBe(['first', 'second'])
        ->and($seen)->toBe(['first', 'second']);
});

it('composes repeatable native callback attributes in declaration order on cold and warm calls', function (): void {
    $factory = new FactoryStub();
    $pipeline = callbackAttributePipeline($factory);
    $target = new CallbackAttributeTarget();

    expect($pipeline->call([$target, 'ordered']))->toBe('outer:around:inner:value')
        ->and($pipeline->call([$target, 'ordered']))->toBe('outer:around:inner:value')
        ->and($factory->calls)->toBe([]);
});

it('does not materialize later attributes or invoke the target after a callback short circuit', function (): void {
    $factory = new FactoryStub();
    $pipeline = callbackAttributePipeline($factory);

    expect($pipeline->call([new CallbackAttributeTarget(), 'shortCircuit']))->toBe('cached')
        ->and($factory->calls)->toBe([]);
});

it('uses current arguments and fresh native callback state with cached reflection metadata', function (): void {
    $pipeline = callbackAttributePipeline();
    $target = new CallbackAttributeTarget();

    expect($pipeline->call([$target, 'current'], ['first']))->toBe([1, 'first'])
        ->and($pipeline->call([$target, 'current'], ['second']))->toBe([1, 'second']);
});

it('preserves private first class callable scope for each class using a shared trait', function (): void {
    $pipeline = callbackAttributePipeline();

    foreach ([new FirstCallbackAttributeOwner(), new SecondCallbackAttributeOwner(), new FirstCallbackAttributeOwner(), new SecondCallbackAttributeOwner()] as $owner) {
        $expected = $owner instanceof FirstCallbackAttributeOwner ? 'first:value' : 'second:value';
        expect($pipeline->call([$owner, 'inherited']))->toBe($expected);
    }
});

it('accepts a first class function callback on a named function declaration', function (): void {
    expect(callbackAttributePipeline()->call(__NAMESPACE__ . '\\callbackAttributeFunction'))->toBe('FUNCTION');
});

it('does not resolve undeclared service arguments through DI', function (): void {
    $factory = new FactoryStub();

    expect(fn () => callbackAttributePipeline($factory)->call([new CallbackAttributeTarget(), 'extraDependency']))
        ->toThrow(\ArgumentCountError::class)
        ->and($factory->calls)->toBe([]);
});
