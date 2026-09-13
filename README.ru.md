# Componenta Interceptor

![PHP 8.4+](https://img.shields.io/badge/PHP-8.4%2B-blue)
![License MIT](https://img.shields.io/badge/License-MIT-green)

Middleware-конвейер для PHP-коллаблов. Оборачивает любую функцию, метод или замыкание сквозной логикой (логирование, кэширование, транзакции, авторизация, сериализация), объявленной через `pipe()` или атрибуты на методах.

**[English documentation](README.md)**

## Установка

```bash
composer require componenta/interceptor
```

## Требования

- PHP 8.4+
- `psr/container`
- `componenta/di` (`CallableExecutorInterface`, `FactoryInterface`, `ParametersResolver`)
- `componenta/reflection` (ленивое разрешение рефлектора)
- `componenta/config` (опционально — интеграция через `ConfigProvider`)

## Связанные пакеты

| Пакет | Зачем нужен здесь |
|---|---|
| `componenta/di` | Выполняет callable и разрешает недостающие параметры перед запуском цепочки перехватчиков. |
| `componenta/reflection` | Лениво получает рефлексию callable, чтобы атрибутные перехватчики могли читать метод и его атрибуты. |
| `componenta/config` | Регистрирует `CallableContextFactory`, `AttributeInterceptor` и связанные сервисы. |
| `componenta/interceptor-app` | Подготавливает метаданные native-атрибутов через app:build. |
| `componenta/serialize-interceptor` | Готовый перехватчик сериализации результата через Symfony Serializer. |
| `componenta/http-respond-interceptor` | Готовый HTTP-перехватчик, который превращает результат в PSR-7 ответ. |
| `componenta/http-paginate-interceptor` | Готовый HTTP-перехватчик для `PaginatorInterface` -> `ResourcePaginator`. |
| `componenta/pipeline` | Похож по идее цепочки, но работает с PSR-15 HTTP-промежуточными обработчиками; `componenta/interceptor` работает с любыми PHP callable. |

## Быстрый старт

```php
use Componenta\DI\CallableExecutorInterface;
use Componenta\Interceptor\CallbackInterceptorFactory;
use Componenta\Interceptor\InterceptingExecutor;

$executor = new InterceptingExecutor(
    $container->get(CallableExecutorInterface::class),
    CallbackInterceptorFactory::around(
        before: fn ($ctx) => $ctx->withAttribute('started', microtime(true)),
        after:  fn ($result, $ctx) => ['result' => $result, 'ms' => (microtime(true) - $ctx->getAttribute('started')) * 1000],
    ),
);

$result = $executor->call([$controller, 'handle'], ['id' => 42]);
```

## Ключевые концепции

### Перехватчик

Класс, реализующий `InterceptorInterface`. Получает контекст выполнения и handler-продолжение; может выполнять логику до/после, прерывать цепочку или трансформировать результат.

```php
interface InterceptorInterface
{
    public function intercept(
        CallableContextInterface $context,
        ContextHandlerInterface $handler,
    ): mixed;
}
```

### Контекст

Иммутабельный объект с коллаблом, его параметрами, произвольными атрибутами и лениво разрешаемым рефлектором. Мутаторы возвращают новый инстанс:

```php
$context = $context
    ->withParameter('userId', 42)
    ->withAttribute('trace.id', $traceId);
```

### Пайплайн

`InterceptingExecutor` собирает перехватчики в предкомпонованную цепочку при первом использовании. Порядок выполнения — **FIFO**: первый зарегистрированный перехватчик — самый внешний (выполняется первым на входе, последним на выходе):

```php
$executor = new InterceptingExecutor($base, $auth, $logger, $cache);

// Один вызов, один проход по цепочке:
//
//   auth.before → logger.before → cache.before → callable
//                                              → cache.after → logger.after → auth.after
//
// intercept() каждого перехватчика вызывается ровно один раз. Код до
// $handler->handle() выполняется на входе, после — при возврате.
```

`pipe()` возвращает новый иммутабельный пайплайн:

```php
$withTx = $executor->pipe($transactionInterceptor);
```

### Короткое замыкание

Перехватчик может вернуть результат, не вызывая handler (отказ авторизации, попадание в кэш, экран обслуживания). Цепочка останавливается, значение всплывает через внешние перехватчики.

## Атрибуты

Объявление перехватчиков на методах через `#[Intercept]`:

```php
use Componenta\Interceptor\Attribute\Intercept;

final class UserController
{
    #[Intercept(CacheInterceptor::class, ['ttl' => 300])]
    #[Intercept(AuthInterceptor::class)]
    public function show(int $id): User { /* ... */ }
}
```

Разрешение выполняет `AttributeInterceptor` (зарегистрируйте один раз в пайплайне). Инстанс перехватчика создаётся через `FactoryInterface` с указанными `params`. Атрибуты читаются как слои, обёрнутые вокруг метода, **снаружи внутрь** — самый верхний атрибут — это самый внешний слой (входит первым, возвращается последним), самый нижний — ближайший к телу метода.

Размещайте входные перехватчики (авторизация, ограничения частоты, кэш-гейты) над перехватчиками результата (форматирование ответа, сериализация, пагинация). Возврат метода идёт наружу через внутренние слои первыми, поэтому сериализатор, размещённый под response-обёрткой, получает сырой результат и передаёт уже сериализованную строку наверх обёртке:

```php
#[Respond(200, 'application/json')]      // внешний — оборачивает строку в PSR-7 ответ
#[Serialize(context: [...])]             // внутренний — первым принимает возврат
public function show(int $id): User { /* ... */ }
```

Атрибуты могут напрямую реализовывать `InterceptorInterface` — тогда они создаются стандартным механизмом PHP-атрибутов:

```php
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class WrapJson implements InterceptorInterface
{
    public function intercept($ctx, $handler): mixed
    {
        return json_encode($handler->handle($ctx));
    }
}
```

### Скопы

Ограничение области применения перехватчика — реализация `Componenta\Scope\ScopedInterface` на атрибуте или на инстансе:

```php
use Componenta\Interceptor\InterceptorInterface;
use Componenta\Interceptor\Scope;
use Componenta\Scope\ScopedInterface;
use Componenta\Scope\Scopes;

final class RespondInterceptor implements InterceptorInterface, ScopedInterface
{
    public Scopes $scopes {
        get => Scopes::of(Scope::HTTP);
    }

    // ...
}
```

Интегратор указывает текущий скоп, выставляя атрибут контекста до запуска пайплайна:

```php
use Componenta\Interceptor\CallableContext;
use Componenta\Interceptor\Scope;

$context = $context->withAttribute(CallableContext::SCOPE_ATTRIBUTE, Scope::HTTP);
```

Скоп на атрибуте имеет приоритет над скопом на инстансе и проверяется до создания зависимостей интерцептора. Перехватчики без `ScopedInterface` подходят под любой скоп.

Встроенные скопы: `HTTP`, `CONSOLE`, `GRPC`, `QUEUE`, `WEBSOCKET`. Пользовательские скопы можно представить через `Componenta\Scope\ScopeName` или через enum конкретного пакета, реализующий `Componenta\Scope\ScopeInterface`.

## Колбэк-перехватчики

Создание перехватчиков из замыканий без отдельных классов:

```php
use Componenta\Interceptor\CallbackInterceptorFactory as F;

$logger   = F::before(fn ($ctx) => $log->info('calling ' . $ctx->reflector->name));
$envelope = F::after(fn ($result) => ['data' => $result]);
$recover  = F::catch(fn (\Throwable $e) => ['error' => $e->getMessage()]);
$cleanup  = F::finally(fn () => $this->releaseLock());

$tracer = F::around(
    before: fn ($ctx) => $ctx->withAttribute('t0', microtime(true)),
    after:  function ($result, $ctx) use ($log) {
        $log->info(sprintf('%.2fms', (microtime(true) - $ctx->getAttribute('t0')) * 1000));
        return $result;
    },
);
```

## Разрешение параметров

`ParameterResolvingInterceptor` обогащает параметры callable через DI до того, как следующие перехватчики увидят контекст:

```php
new InterceptingExecutor(
    $container->get(CallableExecutorInterface::class),
    new ParameterResolvingInterceptor($parametersResolver), // самый внешний — выполнится первым
    $container->get(AttributeInterceptor::class),
);
```

Это позволяет атрибутным перехватчикам читать разрешённые аргументы (например, `$ctx->parameters` для ключа кэша).

Атрибуты параметров выполняются один раз при подготовке. Конечный обработчик передаёт готовые аргументы настроенному executor через адаптер PreparedCallable из DI, включая изменения последующих интерцепторов. Декораторы executor продолжают выполняться; вложенный InterceptingExecutor сохраняет исходный callable и его атрибуты метода. Изменение атрибутов контекста не запускает подготовку повторно. Вложенные интерцепторы разрешения параметров используют уже готовые аргументы.

Чтение, изменение и удаление подготовленного фиксированного параметра по имени или позиции относятся к одному аргументу. Явный null в параметрах и атрибутах контекста сохраняется даже при переданном fallback; fallback применяется только к отсутствующему ключу.

PreparedCallable распознаётся в call(), handle() и intercept(), включая контексты из CallableContextFactory. Вложенные адаптеры разворачиваются до исходного callable. Эти входы сохраняют атрибуты контекста и метаданные исходного callable, не разрешая его параметры повторно.

Аргументы PreparedCallable связываются по правилам PHP: целочисленные ключи — в порядке добавления, строковые — по имени параметра. До изменения аргументов сохраняются и нативные ошибки привязки. Редактирование параметров через контекст включает логическую привязку по имени и позиции, поэтому удаление фиксированного аргумента не сдвигает соседние.

Подготовленные параметры — окончательные значения для вызова: обновление по имени или позиции заменяет соответствующий аргумент, а пропущенные необязательные аргументы получают значения по умолчанию PHP. DI не восстанавливает аргументы, удалённые после подготовки. Замена callable через withCallable() запускает обычное разрешение DI для новой цели. Pipeline без ParameterResolvingInterceptor продолжает вызывать callable через настроенный DI executor.

## Кэширование

`AttributeInterceptor` кеширует native `ReflectionAttribute` для методов и именованных функций. Каждый вызов заново создаёт аргументы и экземпляры атрибутов через `ReflectionAttribute::newInstance()`, а для `Intercept` вызывает `FactoryInterface::make()`. Ограничения scope проверяются на свежих экземплярах.

Если класс атрибута пока недоступен, его декларация проверяется повторно при следующих вызовах. Неполная классификация не сохраняется в кеше метаданных.

Экземпляры интерцепторов, явно переданные в `InterceptingExecutor` или `pipe()`, сохраняют заданное приложением время жизни. Атрибутные интерцепторы не разделяют изменяемое состояние между вызовами. Замыкания не удерживаются в кеше метаданных.

`componenta/interceptor-app` подготавливает обычный массив позиций атрибутов через `app:build`. Карта сокращает повторную классификацию классов атрибутов и позволяет пропустить чтение атрибутов для методов без интерцепторов. Создание аргументов, допустимость target и повторяемость проверяет PHP. Runtime работает и без артефакта.

Версия 3 использует DI 5 и Config 3. Удалены ключи `COMPILED_INTERCEPTORS`, `COMPILED_INTERCEPTORS_FILE`, `CACHE_VERSION` и формат массива дескрипторов в конструкторе. Настройкой `interceptors.map_file` управляет App-интеграция. Старый кеш цепочек со слабой ссылкой на конечный обработчик удалён.

## Подключение в контейнер

Зарегистрируйте `ConfigProvider` модуля в приложении:

```php
new \Componenta\Interceptor\ConfigProvider();
```

Он регистрирует `CallableContextFactory`, `AttributeInterceptor` и `PipelineInterface`. Сервис `PipelineInterface` предназначен для выполнения HTTP-обработчиков маршрутов и создается через `HttpInterceptorPipelineFactory`:

1. `ParameterResolvingInterceptor` всегда регистрируется первым, поэтому параметры callable разрешаются через DI до запуска прикладных перехватчиков.
2. Дополнительные перехватчики берутся из `ConfigKey::HTTP_INTERCEPTORS`.
3. Каждый элемент списка может быть идентификатором сервиса в контейнере или готовым объектом `InterceptorInterface`.

Типовая HTTP-конфигурация:

```php
use Componenta\Interceptor\AttributeInterceptor;
use Componenta\Interceptor\ConfigKey;

return [
    ConfigKey::HTTP_INTERCEPTORS => [
        AttributeInterceptor::class,
    ],
];
```

`componenta/router-app` использует `PipelineInterface` при выполнении обработчиков маршрутов. Обычно приложение задает список перехватчиков в `src/ConfigProvider.php`.

## Лицензия

MIT
