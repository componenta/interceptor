<?php

declare(strict_types=1);

namespace Componenta\Interceptor\Internal;

use Closure;
use TypeError;

/** @internal Captures an invocable target without granting the library's private scope. */
final class NativeCallable
{
    private function __construct() {}

    public static function capture(callable|string|array $target): callable
    {
        if (is_array($target)) {
            $target = ArraySlots::snapshot($target);
        }
        if (is_array($target) || is_string($target)) {
            // Validate without a class scope so a private library method cannot
            // accidentally become callable through this context.
            static $validate = null;
            $validate ??= Closure::bind(static fn (mixed $value): bool => is_callable($value), null, null);
            if (!$validate($target)) {
                throw new TypeError('Expected a valid PHP callable.');
            }
        }
        return $target;
    }
}
