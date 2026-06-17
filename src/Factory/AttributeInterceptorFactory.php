<?php

declare(strict_types=1);

namespace Componenta\Interceptor\Factory;

use Componenta\Config\Config;
use Componenta\DI\FactoryInterface;
use Componenta\Interceptor\AttributeInterceptor;
use Componenta\Interceptor\ConfigKey;
use Psr\Container\ContainerInterface;

final readonly class AttributeInterceptorFactory
{
    public function __invoke(ContainerInterface $container): AttributeInterceptor
    {
        $config = $container->get(ConfigKey::CONFIG);
        $compiled = $config->get(ConfigKey::COMPILED_INTERCEPTORS, []);

        return new AttributeInterceptor(
            $container->get(FactoryInterface::class),
            is_array($compiled) && $compiled !== []
                ? $compiled
                : $this->loadCompiledFile($config),
        );
    }

    private function loadCompiledFile(Config $config): array
    {
        $file = $config->get(ConfigKey::COMPILED_INTERCEPTORS_FILE, null);

        if (!is_string($file) || $file === '') {
            return [];
        }

        if (!is_file($file)) {
            return [];
        }

        $payload = require $file;

        if (!is_array($payload) || ($payload['version'] ?? null) !== ConfigKey::CACHE_VERSION) {
            return [];
        }

        $map = $payload['map'] ?? [];

        return is_array($map) ? $map : [];
    }

}
