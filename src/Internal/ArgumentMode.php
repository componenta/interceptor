<?php

declare(strict_types=1);

namespace Componenta\Interceptor\Internal;

/** @internal Selects binding semantics without exposing the representation. */
enum ArgumentMode
{
    case Supplied;
    case Native;
    case Logical;
}
