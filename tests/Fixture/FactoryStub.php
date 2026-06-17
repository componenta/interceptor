<?php

declare(strict_types=1);

namespace Componenta\Interceptor\Tests\Fixture;

use Componenta\DI\FactoryInterface;
use Componenta\DI\ProxyType;
use RuntimeException;

/**
 * In-memory {@see FactoryInterface} for tests.
 *
 * Records every {@see make()} call so tests can assert caching behavior.
 */
final class FactoryStub implements FactoryInterface
{
    /** @var array<class-string, callable(array<string, mixed>): object> */
    private array $producers = [];

    /** @var array<int, array{class-string, array<string, mixed>}> */
    public array $calls = [];

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param callable(array<string, mixed>): T $producer
     */
    public function bind(string $class, callable $producer): void
    {
        $this->producers[$class] = $producer;
    }

    public function make(string $entry, array $params = [], ?ProxyType $type = null): object
    {
        $this->calls[] = [$entry, $params];

        if (!isset($this->producers[$entry])) {
            throw new RuntimeException("FactoryStub has no producer bound for {$entry}");
        }

        return ($this->producers[$entry])($params);
    }
}
