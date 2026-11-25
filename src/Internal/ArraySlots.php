<?php

declare(strict_types=1);

namespace Componenta\Interceptor\Internal;

/** @internal Capture top-level array slots without changing object identity or keys. */
final class ArraySlots
{
    private function __construct() {}

    /**
     * @template TKey of array-key
     * @template TValue
     * @param array<TKey, TValue> $values
     * @return array<TKey, TValue>
     */
    public static function snapshot(array $values): array
    {
        $snapshot = [];
        foreach ($values as $key => $value) {
            $snapshot[$key] = $value;
        }
        return $snapshot;
    }
}
