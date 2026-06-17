<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\DI\FactoryInterface;
use Componenta\Interceptor\AttributeInterceptor;
use Componenta\Interceptor\ConfigKey;
use Componenta\Interceptor\Factory\AttributeInterceptorFactory;
use Componenta\Interceptor\Tests\Fixture\FactoryStub;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

function interceptorFactoryContainer(Config $config, FactoryInterface $factory): ContainerInterface
{
    return new class ($config, $factory) implements ContainerInterface {
        public function __construct(
            private Config $config,
            private FactoryInterface $factory,
        ) {}

        public function get(string $id): mixed
        {
            return match ($id) {
                ConfigKey::CONFIG => $this->config,
                FactoryInterface::class => $this->factory,
                default => throw new class ("No entry for {$id}") extends RuntimeException implements NotFoundExceptionInterface {},
            };
        }

        public function has(string $id): bool
        {
            return in_array($id, [ConfigKey::CONFIG, FactoryInterface::class], true);
        }
    };
}

describe('AttributeInterceptorFactory', function () {
    it('does not require app path resolver when compiled interceptor cache is not configured', function () {
        $interceptor = (new AttributeInterceptorFactory())(interceptorFactoryContainer(
            new Config([]),
            new FactoryStub(),
        ));

        expect($interceptor)->toBeInstanceOf(AttributeInterceptor::class);
    });

    it('loads compiled interceptors from the configured cache file path', function () {
        $file = tempnam(sys_get_temp_dir(), 'componenta-interceptor-cache-');
        if ($file === false) {
            throw new RuntimeException('Unable to create temp file.');
        }

        file_put_contents($file, '<?php return ' . var_export([
            'version' => ConfigKey::CACHE_VERSION,
            'map' => [
                'SomeClass::method' => [],
            ],
        ], true) . ';');

        try {
            $interceptor = (new AttributeInterceptorFactory())(interceptorFactoryContainer(
                new Config([ConfigKey::COMPILED_INTERCEPTORS_FILE => $file]),
                new FactoryStub(),
            ));

            expect($interceptor)->toBeInstanceOf(AttributeInterceptor::class);
        } finally {
            unlink($file);
        }
    });
});
