<?php

declare(strict_types=1);

namespace Componenta\Interceptor\Tests\Fixture;

use Componenta\Interceptor\Attribute\Intercept;

#[Intercept(RecordingInterceptor::class, ['name' => 'F'])]
function targetFunction(): string
{
    return 'fn';
}
