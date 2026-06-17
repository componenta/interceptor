<?php

declare(strict_types=1);

namespace Componenta\Interceptor\Tests\Fixture;

use Componenta\Interceptor\Attribute\Intercept;
use Componenta\Interceptor\Scope;
use Componenta\Interceptor\Tests\Fixture\Attribute\AttributeOverridesInstanceScopeIntercept;
use Componenta\Interceptor\Tests\Fixture\Attribute\RecordingIntercept;
use Componenta\Interceptor\Tests\Fixture\Attribute\ScopedRecordingIntercept;
use Componenta\Interceptor\Tests\Fixture\Attribute\WrapResultAttribute;

/**
 * Method fixtures exercising every attribute-handling branch
 * of {@see \Componenta\Interceptor\AttributeInterceptor}.
 */
final class TargetClass
{
    public function plain(): string
    {
        return 'plain';
    }

    #[RecordingIntercept(name: 'A')]
    public function single(): string
    {
        return 'single';
    }

    #[RecordingIntercept(name: 'A')]
    #[RecordingIntercept(name: 'B')]
    #[RecordingIntercept(name: 'C')]
    public function multiple(): string
    {
        return 'multiple';
    }

    #[ScopedRecordingIntercept(name: 'A', scopes: [Scope::HTTP])]
    public function attributeScopedHttp(): string
    {
        return 'attr-scoped';
    }

    #[Intercept(ScopedRecordingInterceptor::class, ['name' => 'A', 'scopes' => [Scope::HTTP]])]
    public function instanceScopedHttp(): string
    {
        return 'inst-scoped';
    }

    #[AttributeOverridesInstanceScopeIntercept(name: 'A')]
    public function attributeOverridesInstance(): string
    {
        return 'override';
    }

    #[WrapResultAttribute(marker: 'wrap')]
    public function direct(): string
    {
        return 'direct';
    }

    #[WrapResultAttribute(marker: 'outer')]
    #[WrapResultAttribute(marker: 'inner')]
    public function directMultiple(): string
    {
        return 'value';
    }
}
