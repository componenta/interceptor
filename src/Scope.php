<?php

declare(strict_types=1);

namespace Componenta\Interceptor;

use Componenta\Scope\ScopeInterface;

/**
 * Standard execution scope identifiers.
 */
enum Scope: string implements ScopeInterface
{
    case HTTP = 'http';
    case CONSOLE = 'cli';
    case GRPC = 'grpc';
    case QUEUE = 'queue';
    case WEBSOCKET = 'websocket';

    public function matches(ScopeInterface $scope): bool
    {
        return $this->value === $scope->value;
    }
}
