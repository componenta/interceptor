<?php

declare(strict_types=1);

namespace Componenta\Interceptor\Attribute;

use Attribute;
use Componenta\Interceptor\Internal\ArraySlots;
use InvalidArgumentException;

/**
 * Declares an interceptor service resolved through DI's FactoryInterface.
 *
 * Subclasses may implement ScopedInterface to restrict scope at the attribute
 * level. Attribute-level scope takes priority over interceptor-level scope.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
class Intercept
{
    /** @var non-empty-string */
    public readonly string $interceptor;
    /** @var array<string|int, mixed> */
    public readonly array $params;

    /**
     * @param string $interceptor Factory entry identifier.
     * @param array<string|int, mixed> $params Constructor parameters.
     */
    public function __construct(string $interceptor, array $params = [])
    {
        if ($interceptor === '') {
            throw new InvalidArgumentException('Interceptor factory entry must be non-empty.');
        }

        $this->interceptor = $interceptor;
        $this->params = ArraySlots::snapshot($params);
    }
}
