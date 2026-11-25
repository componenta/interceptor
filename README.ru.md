# Componenta Interceptor

![PHP 8.5.13+](https://img.shields.io/badge/PHP-8.5.13%2B-blue)
![License MIT](https://img.shields.io/badge/License-MIT-green)

Middleware-конвейер для PHP-коллаблов. Оборачивает любую функцию, метод или замыкание сквозной логикой (логирование, кэширование, транзакции, авторизация, сериализация), объявленной через `pipe()` или атрибуты на методах.

**[English documentation](README.md)**

## Установка

```bash
composer require componenta/interceptor
```

## Требования

- PHP 8.5.13+
- `componenta/di` 6 (`CallableExecutorInterface`, `CallableInvokerInterface`, `FactoryInterface`, `ParametersResolverInterface`)
- `componenta/reflection` 3 (ленивое разрешение рефлектора)
- `componenta/config` (опционально — интеграция через `ConfigProvider`)

## Связанные пакеты

| Пакет | Зачем нужен здесь |
|---|---|
| `componenta/di` | Выполняет callable и разрешает недостающие параметры перед запуском цепочки перехватчиков. |
| `componenta/reflection` | Лениво получает рефлексию callable, чтобы атрибутные перехватчики могли читать метод и его атрибуты. |
| `componenta/config` | Регистрирует `CallableContextFactoryInterface`, `AttributeInterceptor` и связанные сервисы. |
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

Рефлексия определяется по самому вызываемому обработчику. Свойство `reflector` равно `null` при динамическом вызове без конкретной декларации. Элементы массива callable, параметров и атрибутов верхнего уровня фиксируются по значению. Объекты и вложенные значения со ссылками не клонируются глубоко.

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
    #[Intercept(AuthInterceptor::class)]
    #[Intercept(CacheInterceptor::class, ['ttl' => 300])]
    public function show(int $id): User { /* ... */ }
}
```

Разрешение выполняет `AttributeInterceptor` (зарегистрируйте один раз в пайплайне). Инстанс перехватчика создаётся через `FactoryInterface` с указанными `params`. Атрибуты читаются как слои, обёрнутые вокруг метода, **снаружи внутрь** — самый верхний атрибут — это самый внешний слой (входит первым, возвращается последним), самый нижний — ближайший к телу метода.

Размещайте авторизацию перед кеширующим перехватчиком, который может завершить цепочку досрочно. Размещайте входные перехватчики (авторизация, ограничения частоты, кэш-гейты) над перехватчиками результата (форматирование ответа, сериализация, пагинация). Возврат метода идёт наружу через внутренние слои первыми, поэтому сериализатор, размещённый под response-обёрткой, получает сырой результат и передаёт уже сериализованную строку наверх обёртке:

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
use Componenta\Interceptor\CallableContextInterface;
use Componenta\Interceptor\Scope;

$context = $context->withAttribute(CallableContextInterface::SCOPE_ATTRIBUTE, Scope::HTTP);
```

Скоп на атрибуте имеет приоритет над скопом на инстансе и проверяется до создания зависимостей интерцептора. Перехватчики без `ScopedInterface` подходят под любую область применения. Отсутствующее значение или `null` отключает фильтрацию; любое другое значение должно реализовать `ScopeInterface`.

Встроенные скопы: `HTTP`, `CONSOLE`, `GRPC`, `QUEUE`, `WEBSOCKET`. Пользовательские скопы можно представить через `Componenta\Scope\ScopeName` или через enum конкретного пакета, реализующий `Componenta\Scope\ScopeInterface`.

## Колбэк-перехватчики

Создание перехватчиков из замыканий без отдельных классов:

```php
use Componenta\Interceptor\CallbackInterceptorFactory as F;

$logger   = F::before(fn ($ctx) => $log->info('calling ' . ($ctx->reflector?->name ?? 'динамический вызов')));
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

Ключи атрибутов подчиняются правилам PHP: целочисленные строковые имена сохраняются в нормализованном виде. Создание контекста с областью применения заменяет только ключ области.

## Разрешение параметров

`ParameterResolvingInterceptor` обогащает параметры callable через DI до того, как следующие перехватчики увидят контекст:

```php
new InterceptingExecutor(
    $container->get(CallableExecutorInterface::class),
    new ParameterResolvingInterceptor($container->get(ParametersResolverInterface::class)), // самый внешний — выполнится первым
    $container->get(AttributeInterceptor::class),
);
```

Конструктор принимает интерфейс `ParametersResolverInterface` из DI, а не конкретную реализацию разрешателя. Контракт возвращает нативный список аргументов с развёрнутыми вариативными значениями. Исключения DI передаются без изменений; другие ошибки подготовки оборачиваются в `InvalidCallableException` с исходной причиной. Исключения последующих обработчиков не преобразуются этим перехватчиком.

Это позволяет атрибутным перехватчикам читать разрешённые аргументы (например, `$ctx->parameters` для ключа кэша).

Атрибуты параметров выполняются один раз при подготовке. Конечный обработчик передаёт готовые аргументы настроенному executor через адаптер PreparedCallable из DI, включая изменения последующих интерцепторов. Декораторы executor продолжают выполняться; вложенный InterceptingExecutor сохраняет исходный callable и его атрибуты метода. Изменение атрибутов контекста не запускает подготовку повторно. Вложенные интерцепторы разрешения параметров используют уже готовые аргументы.

Чтение, изменение и удаление подготовленного фиксированного параметра по имени или позиции относятся к одному аргументу. Явный null в параметрах и атрибутах контекста сохраняется даже при переданном fallback; fallback применяется только к отсутствующему ключу.

`CallableContext` распознаёт `PreparedCallable` при создании и замене обработчика через `withCallable()`. Вложенные адаптеры разворачиваются, `getCallable()` возвращает исходный обработчик. Свойство `prepared` сообщает, что аргументы окончательные. `withPreparedParameters()` создаёт контекст с готовым нативным списком аргументов, `invocationArguments()` возвращает аргументы для исполнения. Подготовка параметров и конечный обработчик используют контракт контекста, а не его конкретную реализацию.

Аргументы PreparedCallable связываются по правилам PHP: целочисленные ключи — в порядке добавления, строковые — по имени параметра. До изменения аргументов сохраняются и нативные ошибки привязки. Редактирование параметров через контекст включает логическую привязку по имени и позиции, поэтому удаление фиксированного аргумента не сдвигает соседние.

Подготовленные параметры — окончательные значения для вызова: обновление по имени или позиции заменяет соответствующий аргумент; при логическом редактировании именованное значение имеет приоритет над дублирующей позиционной записью, которая удаляется; а пропущенные необязательные аргументы получают значения по умолчанию PHP. DI не восстанавливает аргументы, удалённые после подготовки. Замена обработчика через `withCallable()` запускает обычное разрешение DI для новой цели, если не передан `PreparedCallable`. Подготовленная замена наследует логические позиции аргументов, если не передан явный список; пропущенные значения берутся из новой цели. При замене на обычный обработчик унаследованные подготовленные вариативные значения объединяются в массив вариативного параметра DI с сохранением имён и порядка. Если сигнатуры несовместимы или готовые аргументы нового `PreparedCallable` отличаются от унаследованных исходных значений, передайте явный список. Конвейер без ParameterResolvingInterceptor продолжает вызывать обработчик через настроенный исполнитель DI. Уже разрешённое строковое имя функции или метода не интерпретируется повторно как идентификатор сервиса.

Подготовленные аргументы и элементы контекста верхнего уровня передаются по значению. Ссылочные элементы массива вызываемого обработчика, возвращённого разрешателем, также отделяются от исходных ссылок. Изменение аргументов по ссылке через этот конвейер не поддерживается; для нативного вызова с ссылочными аргументами используйте `CallableInvoker` из DI. Для передачи атрибутов контекста между вложенными конвейерами используйте `pipe()`: контракт DI `call()` переносит только обработчик и аргументы.

## Кэширование

`AttributeInterceptor` кеширует native `ReflectionAttribute` для методов и именованных функций. Каждый достигнутый слой заново создаёт аргументы и экземпляры атрибутов через `ReflectionAttribute::newInstance()`, а для `Intercept` вызывает `FactoryInterface::make()`. Ограничения области применения проверяются на атрибуте и экземпляре, возвращённом фабрикой; контракт фабрики допускает повторное использование экземпляров.

Если класс атрибута пока недоступен, его декларация проверяется повторно при следующих вызовах. Неполная классификация не сохраняется в кеше метаданных.

Экземпляры интерцепторов, явно переданные в `InterceptingExecutor` или `pipe()`, сохраняют заданное приложением время жизни. Нативные экземпляры атрибутов и создаваемые ими аргументы формируются заново. Время жизни экземпляров, полученных из фабрики, определяется её контрактом. Замыкания не удерживаются в кеше метаданных.

Источником истины являются нативные декларации. Перехват не требует генерации карты атрибутов или сборки приложения. Метаданные стабильных деклараций классифицируются один раз для каждой сигнатуры; недоступные классы атрибутов проверяются повторно. Создание аргументов, допустимость цели и повторяемость проверяет PHP при достижении декларации. Досрочное завершение пропускает создание и исполнение внутренних слоёв. Цепочка атрибутов и область применения выбираются при входе в `AttributeInterceptor`. Заменяйте обработчик или область применения до этого перехватчика, если необходимо изменить выбор. Последующие доверенные перехватчики могут изменить контекст вызова, но повторного поиска атрибутов не происходит. Не используйте замену цели после выбора цепочки в качестве механизма авторизации.


## Подключение в контейнер

Зарегистрируйте `ConfigProvider` модуля в приложении:

```php
new \Componenta\Interceptor\ConfigProvider();
```

Он регистрирует `CallableContextFactoryInterface`, `AttributeInterceptor` и `PipelineInterface`. Сервис `PipelineInterface` предназначен для выполнения HTTP-обработчиков маршрутов и создается через `HttpInterceptorPipelineFactory`:

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

## Разработка

```bash
composer install
composer test
composer analyse
composer bench:smoke
composer bench
```

Набор бенчмарков сравнивает нативный вызов, DI и различные режимы перехвата на эквивалентных сценариях.

## Лицензия

MIT
