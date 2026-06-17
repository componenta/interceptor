<?php

declare(strict_types=1);

namespace Componenta\Interceptor\Tests\Fixture\Attribute;

use Attribute;
use Componenta\Interceptor\Attribute\Intercept;
use Componenta\Interceptor\Scope;
use Componenta\Interceptor\Tests\Fixture\ScopedRecordingInterceptor;
use Componenta\Scope\ScopedInterface;
use Componenta\Scope\Scopes;

/**
 * Attribute-level scope: HTTP. Resolved interceptor's instance-level scope: CONSOLE.
 *
 * Used to verify that attribute-level scope takes priority over instance-level scope
 * when both are declared.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class AttributeOverridesInstanceScopeIntercept extends Intercept implements ScopedInterface
{
    public Scopes $scopes {
        get => Scopes::of(Scope::HTTP);
    }

    public function __construct(string $name)
    {
        parent::__construct(
            ScopedRecordingInterceptor::class,
            ['name' => $name, 'scopes' => [Scope::CONSOLE]],
        );
    }
}
