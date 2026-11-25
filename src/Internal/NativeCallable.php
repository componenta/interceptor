<?php

declare(strict_types=1);

namespace Componenta\Interceptor\Internal;

use Closure;
use LogicException;
use TypeError;

/** @internal Captures an invocable target without granting the library's private scope. */
final class NativeCallable
{
    private function __construct() {}

    /** @param callable|string|array<array-key, mixed> $target */
    public static function capture(callable|string|array $target): callable
    {
        if (is_array($target)) {
            $target = ArraySlots::snapshot($target);
        }
        if (!self::isPublicCallable($target)) {
            throw new TypeError('Expected a valid PHP callable.');
        }

        return $target;
    }

    /** @phpstan-assert-if-true callable $target */
    private static function isPublicCallable(mixed $target): bool
    {
        // Validate without a class scope, which would otherwise grant access
        // to private library methods.
        static $validate = null;
        if (!$validate instanceof Closure) {
            $validate = Closure::bind(static fn (mixed $value): bool => is_callable($value), null, null)
                ?? throw new LogicException('Unable to create a scope-neutral callable validator.');
        }

        return $validate($target) === true;
    }
}
