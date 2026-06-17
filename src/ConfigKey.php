<?php

declare(strict_types=1);

namespace Componenta\Interceptor;

final class ConfigKey extends \Componenta\Config\ConfigKey
{
    public const int CACHE_VERSION = 1;

    public const string HTTP_INTERCEPTORS = 'interceptors.http';
    public const string COMPILED_INTERCEPTORS = 'Componenta\Interceptor::CompiledInterceptors';
    public const string COMPILED_INTERCEPTORS_FILE = 'Componenta\Interceptor::CompiledInterceptorsFile';
}
