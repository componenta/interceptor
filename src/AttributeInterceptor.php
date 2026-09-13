<?php

declare(strict_types=1);

namespace Componenta\Interceptor;

use Componenta\DI\FactoryInterface;
use Componenta\Interceptor\Attribute\Intercept;
use Componenta\Interceptor\Internal\AttributeMetadata;
use Componenta\Scope\ScopeInterface;
use Componenta\Scope\ScopedInterface;
use ReflectionAttribute;
use UnexpectedValueException;

/**
 * Executes native attributes in declaration order.
 * Only Reflection metadata is cached; attributes, their arguments and
 * factory-created interceptors are materialized afresh for every invocation.
 */
final class AttributeInterceptor implements InterceptorInterface
{
    /** @var array<string, list<ReflectionAttribute<object>>> */
    private array $attributes = [];
    /** @var array<string, list<int>> */
    private readonly array $attributeMap;

    /** @param array<string, list<int>> $attributeMap Attribute positions by callable signature. */
    public function __construct(
        private readonly FactoryInterface $factory,
        array $attributeMap = [],
    ) {
        $this->attributeMap = AttributeMetadata::validMap($attributeMap) ? $attributeMap : [];
    }

    public function intercept(CallableContextInterface $context, ContextHandlerInterface $handler): mixed
    {
        $reflector = $context->reflector;
        $signature = AttributeMetadata::signature($reflector);
        $attributes = $signature === null ? null : ($this->attributes[$signature] ?? null);
        if ($attributes === null) {
            $cacheable = true;
            $attributes = AttributeMetadata::resolve(
                $reflector,
                $signature === null ? null : ($this->attributeMap[$signature] ?? null),
                $cacheable,
            );
            if ($signature !== null && $cacheable) {
                $this->attributes[$signature] = $attributes;
            }
        }
        $scope = $context->attributes[CallableContext::SCOPE_ATTRIBUTE] ?? null;
        $scope = $scope instanceof ScopeInterface ? $scope : null;
        $interceptors = [];
        foreach ($attributes as $metadata) {
            $attribute = $metadata->newInstance();
            $attributeScoped = $scope !== null && $attribute instanceof ScopedInterface;
            if ($attributeScoped && !$attribute->scopes->contains($scope)) {
                continue;
            }
            $instance = $attribute instanceof Intercept
                ? $this->factory->make($attribute->interceptor, $attribute->params)
                : $attribute;
            if (!$instance instanceof InterceptorInterface) {
                throw new UnexpectedValueException(sprintf(
                    'Attribute "%s" resolved %s instead of %s.',
                    $metadata->getName(),
                    get_debug_type($instance),
                    InterceptorInterface::class,
                ));
            }
            if (!$attributeScoped && $scope !== null && $instance instanceof ScopedInterface
                && !$instance->scopes->contains($scope)) {
                continue;
            }
            $interceptors[] = $instance;
        }
        return ChainComposer::compose($interceptors, $handler)->handle($context);
    }

}
