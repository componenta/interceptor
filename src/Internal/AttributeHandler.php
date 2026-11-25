<?php

declare(strict_types=1);

namespace Componenta\Interceptor\Internal;

use Componenta\DI\FactoryInterface;
use Componenta\Interceptor\Attribute\Intercept;
use Componenta\Interceptor\CallableContextInterface;
use Componenta\Interceptor\ContextHandlerInterface;
use Componenta\Interceptor\InterceptorInterface;
use Componenta\Scope\ScopeInterface;
use Componenta\Scope\ScopedInterface;
use ReflectionAttribute;
use UnexpectedValueException;

/** @internal Materializes only the declaration reached by the continuation. */
final readonly class AttributeHandler implements ContextHandlerInterface
{
    /** @param ReflectionAttribute<object> $metadata */
    public function __construct(
        private ReflectionAttribute $metadata,
        private FactoryInterface $factory,
        private ?ScopeInterface $scope,
        private ContextHandlerInterface $next,
    ) {}

    public function handle(CallableContextInterface $context): mixed
    {
        $attribute = $this->metadata->newInstance();
        $attributeScoped = $this->scope !== null && $attribute instanceof ScopedInterface;

        if ($attributeScoped && !$attribute->scopes->contains($this->scope)) {
            return $this->next->handle($context);
        }

        $instance = $attribute instanceof Intercept
            ? $this->factory->make($attribute->interceptor, $attribute->params)
            : $attribute;

        if (!$instance instanceof InterceptorInterface) {
            throw new UnexpectedValueException(sprintf(
                'Attribute "%s" resolved %s instead of %s.',
                $this->metadata->getName(),
                get_debug_type($instance),
                InterceptorInterface::class,
            ));
        }

        if (!$attributeScoped && $this->scope !== null && $instance instanceof ScopedInterface
            && !$instance->scopes->contains($this->scope)) {
            return $this->next->handle($context);
        }

        return $instance->intercept($context, $this->next);
    }
}
