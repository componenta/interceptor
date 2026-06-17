<?php

declare(strict_types=1);

namespace Componenta\Interceptor\Tests\Fixture\Attribute;

use Attribute;
use Componenta\Interceptor\Attribute\Intercept;
use Componenta\Interceptor\Tests\Fixture\RecordingInterceptor;
use Componenta\Scope\ScopeInterface;
use Componenta\Scope\ScopedInterface;
use Componenta\Scope\Scopes;

/**
 * Test attribute that carries its own scope at the attribute level.
 *
 * Resolves to a non-scoped {@see RecordingInterceptor}; the scope is taken
 * from the attribute itself (priority over interceptor-instance scope).
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class ScopedRecordingIntercept extends Intercept implements ScopedInterface
{
    public Scopes $scopes {
        get => Scopes::from($this->scopeItems);
    }

    /**
     * @param list<ScopeInterface> $scopes
     */
    public function __construct(
        string $name,
        array $scopes,
    ) {
        $this->scopeItems = $scopes;
        parent::__construct(RecordingInterceptor::class, ['name' => $name]);
    }

    /**
     * @var list<ScopeInterface>
     */
    private readonly array $scopeItems;
}
