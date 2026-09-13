<?php

declare(strict_types=1);

#[Attribute(Attribute::TARGET_METHOD)]
final class RuntimeLoadedInterceptorAttribute implements \Componenta\Interceptor\InterceptorInterface
{
    public function intercept(\Componenta\Interceptor\CallableContextInterface $context, \Componenta\Interceptor\ContextHandlerInterface $handler): mixed
    {
        return 'wrapped:' . $handler->handle($context);
    }
}
