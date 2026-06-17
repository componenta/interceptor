<?php

declare(strict_types=1);

namespace Componenta\Interceptor;

use Componenta\DI\FactoryInterface;
use Componenta\Interceptor\Attribute\Intercept;
use Componenta\Scope\ScopeInterface;
use Componenta\Scope\ScopedInterface;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use WeakMap;

/**
 * Attribute-based interceptor dispatcher.
 *
 * Resolves and executes interceptors declared via:
 * - {@see Intercept} (and subclasses) - interceptor class + parameters
 *   resolved via DI factory at first encounter.
 * - Attributes implementing {@see InterceptorInterface} directly -
 *   instantiated by {@see \ReflectionAttribute::newInstance()}.
 *
 * Execution order: attribute declarations form a top-down pipeline
 * (the first-declared attribute is the outermost interceptor -
 * runs first in the call direction, last in the unwind direction).
 *
 * Attributes are read as layers wrapped around the method body, from
 * outside in: the topmost attribute is the outermost layer (it enters
 * first and returns last), the bottommost attribute is the innermost
 * layer (closest to the method, first to transform its return value).
 * Place gates (auth, rate-limit, permission) above transformers
 * (respond, serialize, paginate) so the result flows inner -> outer
 * through the transforms first, then back out through the gates.
 *
 * Scope resolution priority:
 * 1. {@see ScopedInterface} on the attribute (highest)
 * 2. {@see ScopedInterface} on the interceptor instance
 * 3. No scope restriction (matches all)
 *
 * Caching:
 * - Candidates per reflector signature: stable lookup of resolved
 *   {@see Intercept}-paired interceptors. Method/function attributes
 *   are immutable within a process lifetime.
 * - Composed chains per (terminal, signature, scope): stored in a
 *   {@see WeakMap} keyed by terminal. Each cached chain holds the
 *   terminal weakly via {@see WeakTerminalHandler} at its innermost
 *   link, so the cache value never strongly references the cache key.
 *   Entries are released by the garbage collector when the terminal
 *   becomes unreachable from outside.
 *
 * Closures bypass both caches - they have no stable signature key.
 */
final class AttributeInterceptor implements InterceptorInterface
{
    /** @var array<string, list<array{?Intercept, InterceptorInterface}>> */
    private array $candidates = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $compiledCandidates;

    /** @var WeakMap<ContextHandlerInterface, array<string, ContextHandlerInterface>> */
    private WeakMap $chains;

    /**
     * @param array<string, list<array<string, mixed>>> $compiledCandidates
     */
    public function __construct(
        private readonly FactoryInterface $factory,
        array $compiledCandidates = [],
    ) {
        $this->compiledCandidates = $compiledCandidates;
        $this->chains = new WeakMap();
    }

    public function intercept(
        CallableContextInterface $context,
        ContextHandlerInterface $handler,
    ): mixed {
        $reflector = $context->reflector;
        $scope = $context->attributes[CallableContext::SCOPE_ATTRIBUTE] ?? null;
        $scope = $scope instanceof ScopeInterface ? $scope : null;
        $signatureKey = $this->signatureKey($reflector);
        $cacheable = $signatureKey !== null;

        if ($cacheable) {
            $perTerminal = $this->chains[$handler] ?? null;
            $chainKey = $signatureKey . '|' . ($scope?->value ?? '');

            if ($perTerminal !== null && isset($perTerminal[$chainKey])) {
                return $perTerminal[$chainKey]->handle($context);
            }

            $candidates = $this->candidates[$signatureKey]
                ??= $this->buildCandidates($reflector);
        } else {
            $candidates = $this->buildCandidates($reflector);
        }

        $chain = ChainComposer::compose(
            $this->filterByScope($candidates, $scope),
            $handler,
            weakInnermost: true,
        );

        // Cache the composed chain when:
        // - the signature is stable (not a closure), and
        // - the chain actually wraps the terminal (storing terminal-as-value
        //   would strongly reference it via the WeakMap and break cleanup).
        if ($cacheable && $chain !== $handler) {
            $perTerminal ??= [];
            $perTerminal[$chainKey] = $chain;
            $this->chains[$handler] = $perTerminal;
        }

        return $chain->handle($context);
    }

    /**
     * @param list<array{?Intercept, InterceptorInterface}> $candidates
     * @return list<InterceptorInterface>
     */
    private function filterByScope(array $candidates, ?ScopeInterface $scope): array
    {
        $filtered = [];

        foreach ($candidates as [$attribute, $interceptor]) {
            if ($this->matchesScope($attribute, $interceptor, $scope)) {
                $filtered[] = $interceptor;
            }
        }

        return $filtered;
    }

    /**
     * @return list<array{?Intercept, InterceptorInterface}>
     */
    private function buildCandidates(ReflectionFunctionAbstract $reflector): array
    {
        $signatureKey = $this->signatureKey($reflector);

        if ($signatureKey !== null && array_key_exists($signatureKey, $this->compiledCandidates)) {
            $compiled = $this->buildCompiledCandidates($this->compiledCandidates[$signatureKey]);

            if ($compiled !== null) {
                return $compiled;
            }
        }

        $attributes = $reflector->getAttributes();

        if ($attributes === []) {
            return [];
        }

        $candidates = [];

        foreach ($attributes as $attribute) {
            $class = $attribute->getName();

            if (is_a($class, Intercept::class, true)) {
                /** @var Intercept $intercept */
                $intercept = $attribute->newInstance();

                /** @var InterceptorInterface $instance */
                $instance = $this->factory->make($intercept->interceptor, $intercept->params);

                $candidates[] = [$intercept, $instance];

                continue;
            }

            if (is_a($class, InterceptorInterface::class, true)) {
                /** @var InterceptorInterface $instance */
                $instance = $attribute->newInstance();

                $candidates[] = [null, $instance];
            }
        }

        return $candidates;
    }

    /**
     * @param list<array<string, mixed>> $descriptors
     * @return list<array{?Intercept, InterceptorInterface}>|null
     */
    private function buildCompiledCandidates(array $descriptors): ?array
    {
        $candidates = [];

        try {
            foreach ($descriptors as $descriptor) {
                if (!is_array($descriptor)) {
                    return null;
                }

                $candidate = match ($descriptor['kind'] ?? null) {
                    'factory' => $this->buildCompiledFactoryCandidate($descriptor),
                    'direct' => $this->buildCompiledDirectCandidate($descriptor),
                    default => null,
                };

                if ($candidate === null) {
                    return null;
                }

                $candidates[] = $candidate;
            }
        } catch (\Throwable) {
            return null;
        }

        return $candidates;
    }

    /**
     * @param array<string, mixed> $descriptor
     * @return array{Intercept, InterceptorInterface}|null
     */
    private function buildCompiledFactoryCandidate(array $descriptor): ?array
    {
        $attributeClass = $descriptor['attribute'] ?? null;
        $arguments = $descriptor['arguments'] ?? [];

        if (!is_string($attributeClass) || !is_array($arguments) || !is_a($attributeClass, Intercept::class, true)) {
            return null;
        }

        /** @var Intercept $intercept */
        $intercept = new $attributeClass(...$arguments);

        $instance = $this->factory->make($intercept->interceptor, $intercept->params);

        return $instance instanceof InterceptorInterface ? [$intercept, $instance] : null;
    }

    /**
     * @param array<string, mixed> $descriptor
     * @return array{null, InterceptorInterface}|null
     */
    private function buildCompiledDirectCandidate(array $descriptor): ?array
    {
        $class = $descriptor['class'] ?? null;
        $arguments = $descriptor['arguments'] ?? [];

        if (!is_string($class) || !is_array($arguments) || !is_a($class, InterceptorInterface::class, true)) {
            return null;
        }

        return [null, new $class(...$arguments)];
    }

    /**
     * Scope match priority:
     * 1. Attribute scope (if Intercept implements ScopedInterface) - wins.
     * 2. Interceptor scope (if instance implements ScopedInterface).
     * 3. No restriction - always matches.
     *
     * When $scope is null, scope filtering is disabled - everything matches.
     */
    private function matchesScope(
        ?Intercept $attribute,
        InterceptorInterface $interceptor,
        ?ScopeInterface $scope,
    ): bool {
        if ($scope === null) {
            return true;
        }

        foreach ([$attribute, $interceptor] as $item) {
            if ($item instanceof ScopedInterface) {
                return $item->scopes->contains($scope);
            }
        }

        return true;
    }

    private function signatureKey(ReflectionFunctionAbstract $reflector): ?string
    {
        if ($reflector instanceof ReflectionMethod) {
            return $reflector->class . '::' . $reflector->name;
        }

        if ($reflector instanceof ReflectionFunction) {
            if ($reflector->isClosure()) {
                return null;
            }

            return $reflector->name;
        }

        return null;
    }
}
