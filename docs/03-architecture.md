# 03. Архитектура проекта

Этот документ описывает внутреннее устройство бэкенда — как запрос проходит через
систему, как устроены DI, RBAC, плагин-система, кэш, event-bus и фоновые задачи.

## 3.1. Слои

```
Request
  │
  ▼
[Middleware]  (CORS, RateLimit, Auth, PermissionCheck, DemoMode)
  │
  ▼
[Action]      (src/Api/Actions/**  или  components/*/Api/**)
  │
  ▼
[Service]     (src/Services/**) — бизнес-логика без HTTP-обвязки
  │
  ▼
[Storage]     (src/Storage/** + components/*/Storage/**) — SQL/кэш
  │
  ▼
[Model]       (src/Models/** + components/*/Models/**) — DTO + @morm аннотации
  │
  ▼
[PDO]  →  MySQL
```

Боковые сервисы:
- **Cache** (`CacheInterface`) → Memcached или Redis, используется в Storage.
- **EventBus** (`EventObserverStorage`) → Redis pub/sub.
- **SwitcherCore** (`src/SwitcherCore/*` + `meklis/switcher-core` lib) → SNMP/Console/API к девайсам.
- **Prometheus metrics** (`PrometheusMetrics`) — пуш через pushgateway-совместимый URL.

## 3.2. Dependency Injection (PHP-DI)

### Контейнер
- `app/dependencies.php` — основные определения: `PDO`, `Logger`, `CacheInterface`, `Redis`, `Memcache`, `App`.
- Autowiring включён глобально + compilation в `var/cache/compiled.php` (`Compiller::init()`).
- В RoadRunner контейнер создаётся один раз на воркер и переиспользуется между запросами.

### Аннотация `@Inject`
В любом классе, разрешаемом через контейнер, пропсы с `@Inject` заполняются автоматически:

```php
class Controller extends AbstractComponentController
{
    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var CacheInterface
     */
    protected $cache;
}
```

Важно: **@Inject работает только на `protected`/`public` пропсах**, не на конструкторе.
Конструктор-инъекция тоже поддерживается (через typehints), но реже используется.

### Доступ из своего кода

```php
$container = App::getInstance()->getContainer();
$storage = $container->get(DeviceStorage::class);
```

## 3.3. Компонентная плагин-система

### Обнаружение
`ComponentInjector` при бутстрапе:
1. Сканирует `components/*/config.php`.
2. Сопоставляет с записями в таблице `system_components` (ключ `name` = `key`).
3. Для включённых (`enabled=1`):
   - Регистрирует routes в Slim-группе `/api/v1/component/<name>/*`.
   - Регистрирует console-команды (префикс `<name>:<cmd>`).
   - Подключает event-listeners (instantiate + subscribe).
   - При первом старте вызывает `Installer::install()` → `executeMigrations()`.
4. Для выключенных пропускает (но миграции остаются).

### Включение/выключение
Через UI (панель настроек → компоненты) или SQL:
```sql
UPDATE system_components SET enabled = 1 WHERE `key` = 'pon_boxes';
```
Изменение требует рестарта воркеров: `docker compose restart wca`.

### Inter-component вызовы
Через `ComponentInjector`:
```php
if ($this->componentInjector->isComponentEnabled('olts')) {
    $oltController = $this->componentInjector->getController('olts');
    $oltController->doSomething();
}
```
Всегда оборачивать в `isComponentEnabled` — компоненты опциональны.

## 3.4. Routing

### Структура URL
```
/api/v1                       # base (из config api.base_path)
  ├── /public/*               # без auth
  ├── /auth, /app-auth        # session login
  ├── /dashboard/*            # core
  ├── /device/*               # core
  ├── /user*                  # core
  ├── /poller/*               # core
  ├── /switcher-core/{storage}/{module}/{device_id}   # прямое SNMP/console
  └── /component/{name}/*     # роуты плагинов (name = ключ компонента)
```

### Регистрация route в компоненте
В `components/<Name>/config.php`:
```php
'routes' => [
    ['methods' => ['GET'],  'pattern' => '/items',      'callable' => \WCC\Name\Api\GetItems::class],
    ['methods' => ['POST'], 'pattern' => '/items',      'callable' => \WCC\Name\Api\CreateItem::class],
    ['methods' => ['GET'],  'pattern' => '/items/{id}', 'callable' => \WCC\Name\Api\GetItem::class],
],
```
Финальный URL: `/api/v1/component/<name>/items`.

### Action-класс
```php
class GetItems extends PrivateAction    // auth + permission
{
    /** @Inject @var ItemStorage */
    protected $storage;

    protected function action(): Response
    {
        $items = $this->storage->fetchAll();
        $data = array_map(fn($i) => $i->getAsArrayLite(), $items);
        return $this->respondWithData($data);
    }
}
```

Action получает:
- `$this->request` (PSR-7 Request)
- `$this->response` (PSR-7 Response)
- `$this->user` (текущий User, только в PrivateAction)
- `$this->request->getAttribute('id')` — параметры маршрута

## 3.5. RBAC (Permissions)

### Правила
`config/rules.yml` (ядро) + `components/<Name>/rules.yml` (компоненты):
```yaml
- key: device_show
  description: Show devices
  logic_group: device_management
  routes:
    - ^(GET):/.*$
```

- `key` — идентификатор права (присваивается группе пользователей).
- `routes` — массив regex'ов `METHODS:PATH`. Если хоть один matches — право требуется для этого маршрута.
- `logic_group` — категория для UI.

### Механизм проверки
1. Middleware `PermissionCheck` берёт permissions текущего юзера (через UserGroup).
2. Для каждого запроса проверяет: есть ли какой-либо rule, чей regex matches текущий route; если да — у юзера должен быть соответствующий `key`.
3. Режимы:
   - `API_STRICT_RULES=yes` — если нет rule для route, запрос **блокируется**.
   - `API_STRICT_RULES=no` — если нет rule, разрешить всем.

### Программная проверка внутри Action
```php
if ($this->user->isRulePermitted('pon_boxes_manage')) {
    // full access
}
```

## 3.6. Authentication

- **Сессии:** `POST /api/v1/auth` { login, password } → возвращает API key (`XAuthKey`).
- **TOTP 2FA:** если у юзера включено — требуется второй шаг (`/auth/2fa`).
- **API-only (app-auth):** `POST /api/v1/app-auth` для интеграций с более длинным TTL.
- **TTL:** `API_KEY_EXPIRATION` (секунды).
- **Header:** `XAuthKey: <token>` на каждый запрос к private routes.

## 3.7. Storage и Models

### Модель (POPO + @morm)
```php
class BoxObject extends AbstractModel
{
    /** @morm @var string */
    protected $created_at;

    /** @morm @var string|null */
    protected $number;

    // ...
}
```
- `@morm` — маркер, что поле маппится на колонку SQL (имя колонки = имя свойства по умолчанию).
- `@morm @prop.display=no` — скрыть в `getAsArrayLite()` (например, FK-колонка).
- `@morm @prop.display=root` — отдать как есть (JSON-поле, массив).

### Storage
```php
class BoxObjectStorage extends AbstractStorage
{
    protected $tableName = 'c_pon_boxes';

    /** @Inject @var DeviceStorage */
    protected $deviceStorage;

    function getById($id) {
        return parent::getObjectById(new BoxObject(), $id);
    }

    public function fill($object, $fillChildObjects = true) {
        $object = parent::fill($object);
        // Догружаем связанные объекты (не FK, а объекты)
        if ($object->device_id && $fillChildObjects) {
            $object->device = $this->deviceStorage->getById($object->device_id);
        }
        return $object;
    }
}
```

Унаследованные методы `AbstractStorage`:
- `add($obj)`, `update($obj)`, `delete($obj)`
- `fill($obj)` — подтягивает из БД по `id` все `@morm` поля
- `fillByArr($obj, $row)` — тот же fill, но из уже прочитанного ассоциативного массива
- `fetchAllIds($where = null, $orderBy = 'id desc')` — низкоуровневый
- `getObjectById(new Class(), $id)`
- `setCache($obj, $prefix = '', $ttl = null)`, `clearCache($obj, $prefix = '')`

## 3.8. Кэш

### Интерфейс `CacheInterface`
`get`, `set`, `delete`, `exists`. Реализации:
- `MemcachedCache` (default при `MEMCACHE_ENABLED=yes`)
- `RedisCache`
- `NullCache` (fallback)

### Политики в Storage
Типичный паттерн — кэшировать `fetchAll` на 15 минут, invalid-ить в `add/update/delete`:
```php
function fetchAll() {
    if ($data = $this->cache->get('MY_KEY')) return $data;
    $data = ...;
    $this->cache->set('MY_KEY', $data, 900);
    return $data;
}

function update($obj) {
    $this->cache->delete('MY_KEY');
    $this->clearCache($obj);
    parent::update($obj);
    return $this->fill($obj);
}
```

## 3.9. Event bus

### Принцип
`EventObserverStorage` хранит список `Observer`. При публикации — вызывает `notify` у
всех, чей `getEventType()` matches. Внутри процесса — синхронно. Между процессами —
через Redis pub/sub.

### Листенер
```php
class PollerEvents extends Observer
{
    /** @Inject @var Logger */
    protected $logger;

    function getEventType() {
        return "poller:finished";
    }

    function notify(\SplSubject $subject, $event, $data = null) {
        if ($data['status'] !== 'success') return;
        // ...
    }
}
```

### Регистрация
В `config.php` компонента:
```php
'event_listeners' => [
    \WCC\Name\Events\PollerEvents::class,
],
```

### Публикация
```php
$observerStorage->publish("poller:finished", ['status' => 'success', 'device_id' => 42]);
```

## 3.10. Poller System

`src/Infrastructure/Poller/` — фоновый опрос устройств:
- Запускается как daemon из `schedule-executor` по крону.
- Concurrency-limit — `POLLER_MAX_CONCURRENT_DEVICES`.
- Для каждого девайса вызывает модули `switcher-core` (interfaces_status, pon_onts_status, fdb, arp и т.д.).
- Результаты пишет в `poller_*` таблицы + обновляет `device_interfaces`.
- По завершении публикует event `poller:finished`.

## 3.11. SwitcherCore

Библиотека `meklis/switcher-core` абстрагирует вендорные протоколы:
```
Request (device + module + params)
  │
  ▼
[SwitcherCore]
  │
  ├─→ SNMP   (Net-SNMP ext + PHP wrapper)
  ├─→ Console (Telnet/SSH через phpseclib)
  └─→ MikroTik API (TCP:8728)
  │
  ▼
Response (structured data)
```

Прямой доступ через REST: `/api/v1/switcher-core/{storage}/{module}/{device_id}`.
Из кода: `$container->get(SwitcherCore::class)->call($device, $moduleName, $params)`.

## 3.12. Metrics

- `PrometheusMetrics` — пуш-ориентированный клиент, хранит gauges в Redis/Memcache.
- Endpoint `/metrics` читает все ключи и отдаёт в Prometheus text format с gzip.
- Компоненты пушат свои метрики:
  ```php
  $this->prom->setGauge('my_metric', $value, ['label' => 'v'], "Help text", $ttlSeconds);
  ```

## 3.13. Schedule (cron)

Таблица `system_schedule`:
- `key` (уникальный), `crontab`, `command` (строка `wca foo:bar`), `state` (`ENABLED`/`DISABLED`).
- Читается `schedule-executor` контейнером (cron → `schedule-executor.sh`).
- Каждая запись — отдельный вызов `wca <cmd>` через supervisor RPC внутрь контейнера `wca`.

Добавление задачи из миграции компонента:
```sql
INSERT IGNORE INTO system_schedule (`key`, created_at, component_id, crontab, command, state, editable)
VALUES ('pon_boxes_recalculate_statuses', NOW(),
        (SELECT id FROM system_components WHERE `key` = 'pon_boxes'),
        '*/3 * * * *', 'wca pon_boxes:recalculate-box-statuses', 'ENABLED', 1);
```

## 3.14. OpenAPI

- Источник: `@OA\*` аннотации в `src/OpenApi/` и в Action-классах.
- Генерация: `wca openapi:generate` → `var/openapi/openapi.yaml`.
- UI: swagger-ui контейнер, примонтированный к файлу.

## 3.15. RoadRunner specifics

- **Воркеры живут долго.** Не пишите singleton-state в статических полях.
- **PDO может отвалиться** при долгой idle. `PdoWrapper` делает авто-reconnect.
- **Memcache connection** держится в воркере. Не закрывайте вручную.
- **Reload воркеров:** `docker compose restart wca` (или `rr reset` изнутри).
- **Graceful reload** после миграций: ядро публикует `system:reload-web-workers` в Redis, воркеры перезапускаются пачкой.

## 3.16. Логирование

- Monolog 2.2, конфигурируется в `app/dependencies.php`.
- Каналы: `system` (RR), `console` (CLI), `component.<name>` (автоматически присваивается в `AbstractComponentController`), `switcher-core` (отдельный handler).
- Файлы — в `var/logs/`, уровень задан `LOG_LEVEL`.
- Компонент использует:
  ```php
  $this->logger->info("message", ['context' => 'data']);
  ```

## 3.17. Ограничения PHP 7.4

Что **нельзя** использовать:
- Union types (`int|string`) — только `?T` (nullable).
- Named arguments (`func(name: 'x')`).
- Enums.
- Readonly properties.
- `first-class callable syntax`.
- `match` expression.
- `str_contains`, `str_starts_with`, `str_ends_with` (polyfill есть в `symfony/polyfill-php80`, но лучше `strpos`).

Что **можно**:
- Typed properties (`protected int $x;`).
- Arrow functions (`fn($x) => $x * 2`).
- Null coalescing assignment (`$a ??= $b`).
- Numeric separators (`1_000`).
- Spread with keys в arrays.
