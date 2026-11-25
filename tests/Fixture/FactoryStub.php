<?php

declare(strict_types=1);

namespace Componenta\Interceptor\Tests\Fixture;

use Componenta\DI\FactoryInterface;
use RuntimeException;

/**
 * In-memory {@see FactoryInterface} for tests.
 *
 * Records every {@see make()} call so tests can observe requested interceptor parameters.
 */
final class FactoryStub implements FactoryInterface
{
    /** @var array<non-empty-string, callable(array<string, mixed>): object> */
    private array $producers = [];

    /** @var array<int, array{non-empty-string, array<string, mixed>}> */
    public array $calls = [];

    /**
     * @template T of object
     * @param non-empty-string $class
     * @param callable(array<string, mixed>): T $producer
     */
    public function bind(string $class, callable $producer): void
    {
        $this->producers[$class] = $producer;
    }

    public function make(string $entry, array $params = []): object
    {
        $this->calls[] = [$entry, $params];

        if (!isset($this->producers[$entry])) {
            throw new RuntimeException("FactoryStub has no producer bound for {$entry}");
        }

        return ($this->producers[$entry])($params);
    }
}
