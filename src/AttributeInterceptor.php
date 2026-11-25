<?php

declare(strict_types=1);

namespace Componenta\Interceptor;

use Componenta\DI\FactoryInterface;
use Componenta\Interceptor\Internal\AttributeHandler;
use Componenta\Interceptor\Internal\AttributeMetadata;
use Componenta\Scope\ScopeInterface;
use InvalidArgumentException;
use ReflectionAttribute;

/**
 * Executes native attributes in declaration order.
 * Only Reflection metadata is cached. Native attributes and arguments are
 * materialized only when reached. Factory-created instances follow the factory's lifetime contract.
 */
final class AttributeInterceptor implements InterceptorInterface
{
    /** @var array<string, list<ReflectionAttribute<object>>> */
    private array $attributes = [];

    public function __construct(
        private readonly FactoryInterface $factory,
    ) {
    }

    public function intercept(CallableContextInterface $context, ContextHandlerInterface $handler): mixed
    {
        $scope = $context->getAttribute(CallableContextInterface::SCOPE_ATTRIBUTE);
        if ($scope !== null && !$scope instanceof ScopeInterface) {
            throw new InvalidArgumentException('The interceptor scope must implement ScopeInterface.');
        }

        $reflector = $context->reflector;
        if ($reflector === null) {
            return $handler->handle($context);
        }
        $signature = AttributeMetadata::signature($reflector);
        $attributes = $signature === null ? null : ($this->attributes[$signature] ?? null);
        if ($attributes === null) {
            $cacheable = true;
            $attributes = AttributeMetadata::resolve($reflector, $cacheable);
            if ($signature !== null && $cacheable) {
                $this->attributes[$signature] = $attributes;
            }
        }
        for ($i = count($attributes) - 1; $i >= 0; --$i) {
            $handler = new AttributeHandler($attributes[$i], $this->factory, $scope, $handler);
        }

        return $handler->handle($context);
    }
}
