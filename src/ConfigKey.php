<?php

declare(strict_types=1);

namespace Componenta\Interceptor;

final class ConfigKey extends \Componenta\Config\ConfigKey
{
    public const string HTTP_INTERCEPTORS = 'interceptors.http';
}
