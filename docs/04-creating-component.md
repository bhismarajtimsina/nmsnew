# 04. Создание нового компонента

Это пошаговый гайд по разработке нового плагин-компонента. Компонент — это
самодостаточный модуль в `components/<Name>/`, добавляющий функциональность
(REST-эндпоинты, CLI-команды, миграции, event-listeners) **без правок ядра**.

> Пример «с нуля» — см. `components/Links/`: standalone-компонент с CRUD, своими
> миграциями, `rules.yml` и console-командой.
>
> **Внимание:** в более ранних редакциях этот документ ссылался на
> `components/PonBoxes/` и `components/UtelsIntegration/`. В текущем дереве таких
> компонентов нет — ссылки заменены на существующие.

## 4.1. Что входит в компонент

Обязательные файлы:

| Файл | Назначение |
|------|------------|
| `config.php` | Манифест: имя, роуты, команды, контроллер, инсталлятор, зависимости. |
| `Installer.php` | Хуки `install/uninstall/enable/disable`. Обычно просто вызывает `executeMigrations()`. |
| `rules.yml` | RBAC-правила для роутов компонента. Если правил нет — можно пустой файл, но ключ `rules` в `config.php` нужен. |
| `migrations/01_init/up.sql` | DDL: как минимум `INSERT INTO system_components ...`. |
| `migrations/01_init/down.sql` | Откат. |

Опциональные файлы:

| Файл / папка | Когда нужно |
|--------------|-------------|
| `Controllers/Controller.php` | Если нужна точка входа для inter-component вызовов или shared-логика между Action'ами. |
| `Api/*.php` | REST-обработчики. Без них компонент — только cron/events. |
| `Console/*.php` | Если нужны CLI-команды. |
| `Events/*.php` (или `Listeners/`) | Event observers. |
| `Models/*.php` | Data-классы, если компонент владеет своими сущностями. |
| `Storage/*.php` | Репозитории для своих моделей. |
| `params.yml` | Env-параметры, отображаемые в UI-настройках (см. `config/env-params.yml` в ядре для формата). |
| `README.md` | Описание компонента (обязательно для прод-кода, формально опционально). |

## 4.2. Структура каталога

Рекомендуемая:

```
components/MyComponent/
├── config.php
├── Installer.php
├── rules.yml
├── params.yml              # опц.
├── README.md
├── migrations/
│   ├── 01_init/
│   │   ├── up.sql
│   │   └── down.sql
│   └── 02_add_xxx/
│       ├── up.sql
│       └── down.sql
├── Api/
│   ├── AbstractMyComponentApi.php     # общий родитель с @Inject-сервисами
│   ├── GetAllItems.php
│   ├── GetItem.php
│   ├── CreateItem.php
│   ├── UpdateItem.php
│   └── DeleteItem.php
├── Console/
│   └── SyncCommand.php
├── Controllers/
│   └── Controller.php
├── Events/
│   └── PollerEventListener.php
├── Models/
│   └── Item.php
└── Storage/
    └── ItemStorage.php
```

**Именование namespace:** `WCC\MyComponent\<subfolder>` (WCC = SupportComponent).
Namespaces автоматически подхватываются composer PSR-4 через `composer.json`:
```json
"autoload": {
    "psr-4": {
        "WCAA\\": "src/",
        "WCC\\": "components/"
    }
}
```
→ после создания новых файлов: `composer dump-autoload` (либо `docker compose restart wca`).

## 4.3. Файл `config.php` — манифест

Разбор всех возможных ключей:

```php
<?php

return [
    // ★ Уникальный ключ компонента. Используется:
    //   - в URL: /api/v1/component/<name>/...
    //   - в БД: system_components.key
    //   - в префиксе console-команд: <name>:<cmd>
    //   - в имени логгер-канала: component.<name>
    'name' => 'my_component',

    // ★ Обязателен. Класс с хуками install/uninstall/enable/disable.
    'installer' => \WCC\MyComponent\Installer::class,

    // ★ Массив правил permissions для роутов компонента.
    // Обычно читается из rules.yml, но можно и инлайн.
    'rules' => yaml_parse_file(__DIR__ . '/rules.yml'),

    // Контроллер компонента — точка входа для inter-component вызовов.
    // Доступен через $componentInjector->getController('my_component').
    // Должен extend AbstractComponentController.
    'controller' => \WCC\MyComponent\Controllers\Controller::class,

    // Список обработчиков event-bus. Каждый должен extend Observer
    // и реализовать getEventType() + notify().
    'event_listeners' => [
        \WCC\MyComponent\Events\PollerEventListener::class,
    ],

    // Устаревший ключ, синоним event_listeners — предпочитайте event_listeners.
    'events' => [],

    // ★ Массив REST-роутов. Префикс /api/v1/component/<name>/ добавляется автоматически.
    'routes' => [
        // Простой роут:
        [
            'methods'  => ['GET'],                                    // массив HTTP-методов
            'pattern'  => '/items',                                   // паттерн Slim (относительно префикса)
            'callable' => \WCC\MyComponent\Api\GetItems::class,       // FQCN класса Action
        ],
        // С параметром:
        [
            'methods'  => ['GET'],
            'pattern'  => '/items/{id}',
            'callable' => \WCC\MyComponent\Api\GetItem::class,
        ],
        // Несколько методов на одном URL:
        [
            'methods'  => ['GET', 'POST'],
            'pattern'  => '/bulk',
            'callable' => \WCC\MyComponent\Api\Bulk::class,
        ],
    ],

    // ★ CLI-команды. Регистрируются с префиксом <name>:<имя_из_config()>.
    // Все должны extend AbstractComponentCommand.
    'console' => [
        \WCC\MyComponent\Console\SyncCommand::class,
    ],

    // Env-параметры, отображаемые в UI настроек.
    // Формат: загружается из params.yml (аналог config/env-params.yml).
    'env_params' => yaml_parse_file(__DIR__ . '/params.yml'),

    // Описание для UI.
    'description' => 'Short human-readable description',

    // Опциональные ключи, используемые отдельными компонентами:
    //
    // 'dependencies' => ['olts'],        // имена компонентов, без которых этот не работает.
    //                                    // ComponentInjector проверяет, но не запрещает enable без них — смотрите isComponentEnabled() в своём Controller.
    //
    // 'rpc' => [],                        // JSON-RPC-методы (см. Events как пример).
    //
    // 'load_modules' => [],               // switcher-core модули, подгружаемые компонентом.
    // 'models_map' => [],                 // маппинг моделей для switcher-core.
    //
    // 'rules_path' => '/path/...',        // кастомный путь для alertmanager custom rules.
    // 'testing_rules_path' => '/path/...',
    //
    // 'database' => [...],                // отдельное подключение к вторичной БД (AllOkBilling).
];
```

### Минимальный config.php

```php
<?php

return [
    'name'        => 'my_component',
    'installer'   => \WCC\MyComponent\Installer::class,
    'rules'       => yaml_parse_file(__DIR__ . '/rules.yml'),
    'routes'      => [],
    'console'     => [],
    'description' => 'My component',
];
```

## 4.4. Installer

Обязательный класс. Типовой шаблон:

```php
<?php

namespace WCC\MyComponent;

use WCAA\Infrastructure\Components\Installer\InstallerAbstract;

class Installer extends InstallerAbstract
{
    /**
     * @Inject
     * @var \PDO
     */
    protected $pdo;

    function install()
    {
        $this->executeMigrations();           // запускает все up.sql из migrations/
    }

    function enable()
    {
        // Вызывается при переключении enabled: 0 → 1.
        // Типично: активировать schedule-задачи.
        $this->pdo->exec("UPDATE system_schedule
            SET state = 'ENABLED'
            WHERE `key` IN ('my_component_job')");
    }

    function disable()
    {
        // Вызывается при enabled: 1 → 0. Отключить cron'ы.
        $this->pdo->exec("UPDATE system_schedule
            SET state = 'DISABLED'
            WHERE `key` IN ('my_component_job')");
    }

    function uninstall()
    {
        $this->executeMigrations('down');     // откат миграций
    }
}
```

## 4.5. Миграции

### Формат
Каждая миграция — папка `migrations/NN_description/` с `up.sql` и опционально `down.sql`.
- Сортировка лексикографическая, поэтому `NN` — двухзначное число с ведущим нулём.
- SQL синтаксис — MySQL 8.

### Обязательная запись в `system_components`
В первой миграции (`01_init/up.sql`) **обязательно**:
```sql
DELETE FROM system_components WHERE `key` = 'my_component';
INSERT INTO system_components (name, `key`, enabled, configuration)
VALUES ('My Component', 'my_component', 1, '{}');
```
Без этой записи `ComponentInjector` не увидит компонент.

### Таблицы компонента
По соглашению именовать с префиксом `c_<name>_<entity>`:
```sql
CREATE TABLE IF NOT EXISTS c_my_component_items
(
    id         INT AUTO_INCREMENT PRIMARY KEY,
    created_at DATETIME NOT NULL,
    name       VARCHAR(150) NOT NULL,
    ...
);
```

### FK на таблицы ядра
Допустимо, но `ON DELETE CASCADE` использовать аккуратно:
```sql
CONSTRAINT c_my_component_items_devices_id_fk
    FOREIGN KEY (device_id) REFERENCES devices (id)
    ON UPDATE SET NULL ON DELETE SET NULL
```

### Schedule-задачи
```sql
INSERT IGNORE INTO system_schedule
    (`key`, created_at, component_id, crontab, command, state, editable)
VALUES (
    'my_component_job', NOW(),
    (SELECT id FROM system_components WHERE `key` = 'my_component'),
    '*/5 * * * *',
    'wca my_component:sync',
    'ENABLED', 1
);
```

### Alertmanager rules (если публикуете метрики)
```sql
INSERT IGNORE INTO c_events_alertmanager_rules
    (created_at, updated_at, group_name, alert_name, expression, `for`, severity,
     annotation_summary, annotation_description, enabled, internal)
VALUES
    (NOW(), NOW(), 'my_component', 'my_alert',
     'my_metric > 0', '10s', 'warning',
     'Summary', 'Description {{ $labels.foo }}', 1, 1);
```

### down.sql
Должен максимально откатывать изменения up.sql, но порядок операций — обратный
(сначала удалить FK/данные, потом таблицы, потом `system_components`).

```sql
DELETE FROM c_events_alertmanager_rules WHERE group_name = 'my_component';
DELETE FROM system_schedule WHERE `key` = 'my_component_job';
DROP TABLE IF EXISTS c_my_component_items;
DELETE FROM system_components WHERE `key` = 'my_component';
```

### Применение
```bash
wca migration:migrate my_component:* --up        # все up
wca migration:migrate my_component:* --down      # все down (обратный порядок)
wca migration:migrate my_component:01_init --up  # одна
```

## 4.6. rules.yml (RBAC)

Формат:
```yaml
- key: my_component_view
  description: Read-only access
  logic_group: my_component
  routes:
    - ^(GET):/.*$

- key: my_component_manage
  description: Full CRUD
  logic_group: my_component
  routes:
    - ^(GET|POST|PUT|DELETE):/.*$
```

**Правила matchинга:**
- `routes` — это regex'ы формата `^(METHODS):PATH$`.
- PATH относителен к префиксу компонента: `/items` означает `/api/v1/component/my_component/items`.
- Если несколько rules matches один маршрут, требуется **хотя бы один** соответствующий permission у юзера.
- В `API_STRICT_RULES=yes` маршрут **без единого matching rule** блокируется для всех кроме admin.

Типичный паттерн — «view» + «manage» + узкие granular permissions (как `pon_boxes_exclude_from_stat`).

## 4.7. params.yml (env-параметры в UI)

Формат (скопирован из `config/env-params.yml`):
```yaml
my_component:
  - param_name: MY_COMPONENT_API_URL
    type: input
    regex: ^https?://.*
    default: 'https://api.example.com'
    rebuild_required: false

  - param_name: MY_COMPONENT_MODE
    type: select
    variants: ['fast', 'safe']
    default: 'safe'
    rebuild_required: false

  - param_name: MY_COMPONENT_ENABLED_FEATURE
    type: checkbox
    default: false

  - param_name: MY_COMPONENT_LIMIT
    type: number
    regex: ^[0-9]+$
    default: 100
```

Типы полей: `input`, `select` (+ `variants`), `checkbox`, `number`, `password`, `textarea`.

Флаг `rebuild_required: true` заставит UI при сохранении переменной перезагрузить воркеры
через `system:reload-web-workers`.

## 4.8. Controllers/Controller.php

Controller — **не** REST-обработчик. Это точка входа для:
1. Inter-component вызовов: `$componentInjector->getController('my_component')->doStuff()`.
2. Переиспользуемой логики между Action'ами и Console-командами (например, `recalculateStatuses`).

```php
<?php

namespace WCC\MyComponent\Controllers;

use Monolog\Logger;
use WCAA\App;
use WCAA\Infrastructure\ComponentInjector;
use WCAA\Infrastructure\Components\AbstractComponentController;
use WCC\MyComponent\Storage\ItemStorage;

class Controller extends AbstractComponentController
{
    /** @Inject @var ItemStorage */
    protected $itemStorage;

    public function __construct(App $app, ComponentInjector $componentInjector, Logger $logger)
    {
        parent::__construct($componentInjector, $logger);
        // optional: подключить зависимые компоненты если включены
        // if ($componentInjector->isComponentEnabled('olts')) {
        //     $this->olts = $componentInjector->getController('olts');
        // }
    }

    public function doStuff($id) {
        $item = $this->itemStorage->getById($id);
        $this->log('INFO', "Processed item {$id}");
        // ...
    }
}
```

Унаследованные методы:
- `log($level, $msg, $tech = null)` — logger + вывод в консоль если установлен (`setConsoleOutput`).
- `$this->logger` — Monolog с каналом `component.<name>`.
- `$this->moduleConfig` — содержимое вашего config.php (можно читать 'description' и т.д.).

## 4.9. API-классы

### Базовый класс
Можно extends `PrivateAction` напрямую, но удобнее сделать абстрактный родитель для
компонента с общими `@Inject` пропсами:

```php
<?php

namespace WCC\MyComponent\Api;

use WCAA\Api\Actions\PrivateAction;
use WCC\MyComponent\Controllers\Controller;
use WCC\MyComponent\Storage\ItemStorage;

abstract class AbstractMyComponentApi extends PrivateAction
{
    /** @Inject @var Controller */
    protected $controller;

    /** @Inject @var ItemStorage */
    protected $itemStorage;
}
```

### Конкретный Action
```php
<?php

namespace WCC\MyComponent\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;

class CreateItem extends AbstractMyComponentApi
{
    protected function action(): Response
    {
        $data = $this->getFormData();

        if (empty($data['name'])) {
            throw new HttpBadRequestException($this->request, "Field 'name' is required");
        }

        $item = (new Item())->setName($data['name']);
        $saved = $this->itemStorage->add($item);

        // Audit log (из PrivateAction)
        $this->addActionSuccess(
            'my_component_create',
            "Created item {$saved->getId()}",
            ['id' => $saved->getId()]
        );

        return $this->respondWithData($saved->getAsArrayLite());
    }
}
```

### Доступные методы/свойства
В `PrivateAction`:
- `$this->request`, `$this->response`, `$this->args` (params маршрута)
- `$this->user` — текущий User
- `$this->getFormData($associative = true)` — POST/PUT body
- `$this->request->getQueryParams()` — query string
- `$this->request->getAttribute('id')` — path parameter
- `$this->respondWithData($data, $meta = null)` — вернуть 200 OK с JSON
- `$this->user->isRulePermitted('my_permission_key')` — granular check
- `$this->getDeviceGroupsIdsFromUser()` — для фильтрации по доступным группам устройств
- `$this->addActionSuccess($action, $message, $meta = [], $device = null)` — аудит-лог
- `$this->addActionFailed($action, $message, $error = null, $meta = [], $device = null)`

### Ошибки
Бросайте PSR-совместимые HTTP-исключения:
- `\Slim\Exception\HttpBadRequestException` → 400
- `\Slim\Exception\HttpForbiddenException` → 403
- `\Slim\Exception\HttpNotFoundException` → 404
- `\WCAA\Api\DomainException\DomainRecordNotFoundException` → 404 с прикладным кодом
- `\WCAA\Exceptions\SupportException` → 500 с логированием

## 4.10. Console-команды

```php
<?php

namespace WCC\MyComponent\Console;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\Infrastructure\Components\AbstractComponentCommand;
use WCC\MyComponent\Controllers\Controller;

class SyncCommand extends AbstractComponentCommand
{
    /** @Inject @var Controller */
    protected $controller;

    function config()
    {
        // Имя автоматически префиксуется: my_component:sync
        $this->setName('sync')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, "Don't apply changes")
            ->setDescription("Sync items from external source");
    }

    function exec(InputInterface $input, OutputInterface $output)
    {
        $this->controller->setConsoleOutput($output)->setIsDebug($output->isDebug());
        $dry = (bool)$input->getOption('dry-run');

        $this->controller->runSync($dry);

        return self::SUCCESS;
    }
}
```

Запуск:
```bash
wca my_component:sync
wca my_component:sync --dry-run
wca my_component:sync -v        # INFO-уровень
wca my_component:sync -vvv      # DEBUG
```

## 4.11. Event listeners

```php
<?php

namespace WCC\MyComponent\Events;

use Monolog\Logger;
use WCAA\Infrastructure\Events\Observer;
use WCC\MyComponent\Controllers\Controller;

class PollerEventListener extends Observer
{
    /** @Inject @var Logger */
    protected $logger;

    /** @Inject @var Controller */
    protected $controller;

    function getEventType()
    {
        // Имя события — любая строка. По соглашению — 'source:action'.
        return "poller:finished";
    }

    function notify(\SplSubject $subject, $event, $data = null)
    {
        if ($data['status'] !== 'success') return;
        if ($data['name'] !== 'interfaces_status') return;

        $this->controller->handlePollerFinished($data['device']['id']);
    }
}
```

Регистрация — в `config.php` → `event_listeners`.

Публикация события из своего кода:
```php
/** @Inject @var \WCAA\Infrastructure\Events\EventObserverStorage */
protected $eventStorage;

// ...
$this->eventStorage->publish("my_component:something_happened", ['id' => 42]);
```

## 4.12. Models + Storage

### Модель

```php
<?php

namespace WCC\MyComponent\Models;

use WCAA\Models\AbstractModel;

class Item extends AbstractModel
{
    function __construct($id = null)
    {
        parent::__construct($id);
        $this->created_at = date("Y-m-d H:i:s");
    }

    /** @morm @var string */
    protected $created_at;

    /** @morm @var string */
    protected $name;

    /** @morm @prop.display=no @var int|null */
    protected $device_id;   // FK — скрываем из getAsArrayLite

    /** @morm @prop.display=root @var array|null */
    protected $params;      // JSON-поле

    // + getters/setters
    public function getName(): string { return $this->name; }
    public function setName(string $name): Item { $this->name = $name; return $this; }
    // ...
}
```

**Аннотации:**
- `@morm` — поле мапится на одноимённую колонку таблицы.
- `@morm @prop.display=no` — не включается в `getAsArrayLite`.
- `@morm @prop.display=root` — выдаётся «как есть» (для JSON-массивов).

### Storage

```php
<?php

namespace WCC\MyComponent\Storage;

use WCAA\Storage\AbstractStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\MyComponent\Models\Item;

class ItemStorage extends AbstractStorage
{
    protected $tableName = 'c_my_component_items';

    /** @Inject @var DeviceStorage */
    protected $deviceStorage;

    function getById($id)
    {
        return parent::getObjectById(new Item(), $id);
    }

    function fetchAll()
    {
        $items = [];
        foreach ($this->fetchAllIds() as $id) {
            $items[] = $this->fill(new Item($id));
        }
        return $items;
    }

    public function fill($obj, $fillChildObjects = true)
    {
        $obj = parent::fill($obj);
        if ($obj->device_id && $fillChildObjects) {
            try {
                $obj->device = $this->deviceStorage->getById($obj->device_id);
            } catch (\Throwable $e) {
                $this->logger->error("Device {$obj->device_id} not found");
            }
        }
        return $obj;
    }
}
```

## 4.13. Пошаговый чеклист создания

1. `mkdir -p components/MyComponent/{Api,Console,Controllers,Events,Models,Storage,migrations/01_init}`
2. Написать `config.php` (минимальный — выше).
3. Написать `Installer.php`.
4. Написать `rules.yml` (хотя бы пустой `[]`, чтобы yaml_parse_file работал).
5. Написать `migrations/01_init/up.sql` (обязательно INSERT в `system_components`) + `down.sql`.
6. Написать `Controllers/Controller.php` (если нужен).
7. Написать модели и storage.
8. Написать API-actions.
9. Написать CLI-команды.
10. Написать event listeners (если нужны).
11. `composer dump-autoload` (либо рестарт контейнера).
12. `wca migration:migrate my_component:* --up`.
13. Проверить, что компонент включён: `SELECT * FROM system_components WHERE \`key\` = 'my_component'`.
14. `docker compose restart wca` — подхватить routes/commands.
15. Тест: `curl http://localhost:8088/api/v1/component/my_component/...` и `wca my_component:<cmd>`.

## 4.14. Типовые ошибки

| Ошибка | Причина |
|--------|---------|
| `Class WCC\...\Controller not found` | composer autoloader не обновлён → `composer dump-autoload` + рестарт. |
| Route вообще не matches | Забыли префикс — в rules.yml и запросах `/api/v1/component/<name>/...` обязателен. |
| `403 Forbidden` на админе | `API_STRICT_RULES=yes` + нет rule под маршрут → добавить в `rules.yml`. |
| `500` + `Component X is not enabled` | Запись `system_components.enabled = 0` — установите 1. |
| Миграции не применяются | Проверьте имя папки (`NN_name`), порядок, наличие файла `up.sql`. |
| Console-команда не находится | Не зарегистрирована в `config.php` → `console`, или не унаследована от `AbstractComponentCommand`. |
| `@Inject` не работает | Аннотация только на `protected`/`public`-пропсах. Также класс должен разрешаться через контейнер (т.е. создаваться `$container->get(...)`, а не `new ...`). |
| RoadRunner держит старый код после правок | `docker compose restart wca`. Dev-стек монтирует код, но воркеры кэшируют классы. |

## 4.15. Где смотреть примеры

| Если нужен... | Смотрите... |
|---------------|-------------|
| CRUD REST API + миграции + cron + алерты | `components/Links/` |
| Интеграция с внешним API + синхронизация устройств | `components/UsersideIntegration/` |
| RPC + event listeners + alertmanager правила | `components/Events/` |
| CLI-инструменты для работы с девайсами | `components/Console/` |
| Poller-events consumer | `components/Pinger/` или `components/FdbHistory/` |
| Интеграция с отдельной БД | `components/AllOkBilling/` |
| Автотопология (сложные алгоритмы) | `components/AutoTopology/` |
