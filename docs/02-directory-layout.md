# 02. Структура каталогов

Дерево корня репозитория с назначением каждой папки и ключевых файлов.

```
support-api/
├── app/                        # HTTP + CLI bootstrap
├── bin/                        # Shell-обёртки для docker exec
├── components/                 # Плагин-модули (30+), опциональны
├── config/                     # Конфиг-файлы ядра (yaml + php)
├── console                     # PHP-entrypoint для Symfony Console
├── docker/                     # Dockerfile + конфиги сервисов
├── docs/                       # Эта документация
├── examples/                   # Helper-скрипты для ручной отладки
├── develop/                    # Локальные dev-скрипты (не в проде)
├── migrations/                 # DB-миграции ядра (без компонентов)
├── public/                     # Публичная статика, отдаётся nginx
├── src/                        # Код ядра
├── status/                     # Индикаторы здоровья сервисов (RR)
├── var/                        # Runtime: логи, кэш, openapi.yaml
├── vendor/                     # Composer-зависимости (gitignored)
├── .env                        # Локальные переменные (gitignored)
├── .env-example                # Шаблон .env
├── composer.json               # Зависимости
├── composer.dev.json           # Dev-зависимости (отдельный lock)
├── docker-compose.yml          # Prod-stack
├── docker-compose.dev.yml      # Dev-stack (монтирует исходники)
├── docker-compose.installer.yml# Одноразовый composer-installer
├── rr                          # RoadRunner CLI
├── README.md                   # Установка (bare-metal + docker)
└── PROJECT_OVERVIEW.md         # Высокоуровневый обзор
```

## app/ — Bootstrap и роутинг

```
app/
├── init.php              # Инициализация: autoload, .env, timezone, App::init()
├── server.php            # Entrypoint воркера RoadRunner (загружается worker-code через RPC)
├── dependencies.php      # Определения DI-контейнера (PHP-DI): PDO, Redis, Memcache, Logger, ...
├── routes.php            # Корневые routes: /api/v1/auth, /api/v1/public/*, вызов ComponentInjector->attachRoutes()
├── middleware.php        # Slim middleware: CORS, RateLimit, SessionAuth, PermissionCheck
├── settings.php          # Глобальные настройки приложения (session, logger, demo mode)
└── refresh_objects_list.php  # Служебный скрипт — обновление списков объектов (CLI)
```

## src/ — Ядро

```
src/
├── App.php                     # Singleton + фасад приложения
│
├── Api/
│   ├── Actions/                # ~114 классов REST-хендлеров (PrivateAction / PublicAction)
│   │   ├── Action.php          # Базовый класс (request, response, respondWithData, getFormData)
│   │   ├── PrivateAction.php   # Auth + permission + demo-mode check
│   │   ├── PublicAction.php    # Без auth
│   │   ├── Auth/               # /auth, /app-auth, /logout, 2FA
│   │   ├── Dashboard/          # Виджеты, шаблоны
│   │   ├── Devices/            # CRUD устройств
│   │   ├── Users/              # Управление пользователями
│   │   ├── Pollers/            # Управление фоновыми опросами
│   │   └── ...
│   ├── Middleware/             # Auth middleware, rate limiter, proxy IP
│   └── DomainException/        # Прикладные исключения (DomainRecordNotFoundException, ...)
│
├── Console/                    # ~64 CLI-команды (Symfony Console)
│   ├── AbstractCommand.php
│   ├── Migrations/             # migration:migrate, migration:status
│   ├── User/                   # user:create-admin, user:list
│   ├── Tests/                  # SNMP walk, ручное тестирование switcher-core
│   └── System/                 # cache:clear, openapi:generate, metrics:export
│
├── Infrastructure/             # Инфраструктурные сервисы
│   ├── ComponentInjector.php   # ★ Главный механизм плагин-системы: discovery, enable/disable, attachRoutes, attachConsole
│   ├── Components/             # Базовые классы для компонентов
│   │   ├── AbstractComponentController.php   # Родитель для controllers компонента
│   │   ├── AbstractComponentCommand.php      # Родитель для console-команд компонента
│   │   └── Installer/InstallerAbstract.php   # Родитель для Installer (executeMigrations)
│   ├── CacheControl.php        # Facade над Memcache
│   ├── CacheSystems/           # Memcache, Redis, Null реализации CacheInterface
│   ├── Compiller.php           # Компиляция PHP-DI (var/cache/compiled.php)
│   ├── Dashboard/              # Виджеты, шаблоны дашбордов
│   ├── EnvParamsEditor.php     # Редактирование .env через UI (config/env-params.yml)
│   ├── Events/
│   │   ├── Observer.php        # Паттерн Observer + Redis pub/sub
│   │   └── EventObserverStorage.php
│   ├── Paginator/              # DbPagination, Paginator
│   ├── PdoWrapper.php          # Обёртка PDO с reconnect + logging
│   ├── Permissions.php         # RBAC: проверка регэкспами из rules.yml
│   ├── Poller/                 # Фоновый опрос устройств (concurrency control)
│   ├── PrometheusMetrics.php   # Клиент Prometheus pushgateway-style
│   ├── Security/               # TOTP, password hashing, session
│   ├── StorageMigrationSystem/ # Запуск миграций компонентов и ядра
│   ├── Supervisor.php          # Клиент supervisor RPC для schedule-executor
│   ├── SystemActionLogger.php  # Аудит-лог пользовательских действий
│   ├── SystemInfo.php          # Данные о системе (version, uptime)
│   └── TrustedIps.php          # Белый список IP/subnet
│
├── Interfaces/                 # CacheInterface, StorageInterface, и т.д.
│
├── Models/                     # Data-классы (POPO + @morm annotations)
│   ├── AbstractModel.php       # Базовый: getAsArrayLite, setFromArr, id
│   ├── Devices/                # Device, DeviceInterface, DeviceGroup, DeviceModel
│   ├── User/                   # User, UserGroup, UserAuthKey
│   ├── Pollers/                # FdbHistory, ArpHistory, PollerProcessing
│   └── ...
│
├── OpenApi/                    # Классы-контейнеры @OA\* аннотаций для swagger-php
│
├── Services/                   # Бизнес-логика ядра (без REST/console-обвязки)
│   ├── DeviceService/
│   ├── UserService/
│   └── ...
│
├── Storage/                    # Репозитории (AbstractStorage extends StorageInterface)
│   ├── AbstractStorage.php     # Обобщённый CRUD: add, update, delete, fill, getObjectById, fetchAllIds
│   ├── Devices/                # DeviceStorage, DeviceInterfaceStorage, DeviceGroupStorage
│   ├── UserStorage.php
│   └── ...
│
├── SwitcherCore/               # Адаптер к meklis/switcher-core (SNMP/Console/MikTik API)
│   ├── Request.php, Response.php
│   └── Methods/                # Обёртки модулей switcher-core
│
├── Exceptions/                 # Классы исключений (SupportException, ...)
└── helpers/                    # Глобальные функции (_env, _conf)
```

### Ключевые базовые классы

| Класс | Назначение |
|-------|------------|
| `WCAA\App` | Singleton приложения, доступ к DI, config |
| `WCAA\Api\Actions\Action` | Базовый REST-action: `request`, `response`, `respondWithData`, `getFormData` |
| `WCAA\Api\Actions\PrivateAction` | Добавляет auth + permissions + demo-mode |
| `WCAA\Infrastructure\Components\AbstractComponentController` | Родитель controllers модулей: `log`, `setConsoleOutput`, `$moduleConfig` |
| `WCAA\Infrastructure\Components\AbstractComponentCommand` | Родитель CLI-команд модулей: автопрефикс имени `<module>:<cmd>` |
| `WCAA\Infrastructure\Components\Installer\InstallerAbstract` | Родитель Installer: `executeMigrations('up'\|'down')` |
| `WCAA\Storage\AbstractStorage` | CRUD-репозиторий, понимает `@morm` аннотации |
| `WCAA\Models\AbstractModel` | Базовая модель: `getAsArrayLite`, `getId`, `setFromArr` |
| `WCAA\Infrastructure\Events\Observer` | Базовый event listener: `notify`, `getEventType` |

## components/ — Плагин-модули

```
components/
├── <Name>/
│   ├── config.php              # ★ Манифест компонента (см. 04-creating-component.md)
│   ├── rules.yml               # Permissions (RBAC) для роутов компонента
│   ├── params.yml              # Env-параметры, отображаемые в настройках UI
│   ├── Installer.php           # install() / uninstall() / enable() / disable()
│   ├── README.md               # Документация компонента
│   ├── migrations/
│   │   └── <NN_name>/up.sql + down.sql
│   ├── Api/                    # REST-actions (обычно extends PrivateAction)
│   ├── Console/                # CLI-команды (extends AbstractComponentCommand)
│   ├── Controllers/
│   │   └── Controller.php      # Точка входа для inter-component вызовов
│   ├── Events/ (или Listeners/)# Event observers
│   ├── Models/                 # Data-классы компонента
│   └── Storage/                # Репозитории компонента
└── ...
```

Компоненты включаются/выключаются в БД (таблица `system_components`, поле
`enabled`) и автообнаруживаются `ComponentInjector` при старте.

## config/ — Конфигурация ядра

```
config/
├── global.php                  # Главный конфиг: читает .env, собирает в массив
├── console.yml                 # Регистрация команд ядра (имя класса → alias)
├── rules.yml                   # RBAC-правила ядра: routes → permissions (regex)
├── env-params.yml              # Описание env-параметров для UI-редактора настроек
├── user-default-parameters.yml # Дефолтные UserParameters для нового юзера
├── ws-permissions.yml          # Permissions для WebSocket-каналов
└── default_dashboard.json      # Seed-данные для дефолтного дашборда
```

## docker/ — Docker-образы

```
docker/
├── roadrunner/                 # wca (main API)
│   ├── Dockerfile              # php-cli + ext + RR binary
│   ├── Dockerfile-debug        # xdebug на борту
│   ├── docker-entrypoint.sh    # запуск rr serve
│   ├── php.ini
│   └── php-debug.ini
├── schedule-executor/          # cron-executor
│   ├── Dockerfile
│   ├── supervisord.conf        # supervisor управляет несколькими demon-процессами
│   ├── cron                    # crontab с вызовами schedule-executor.sh
│   ├── schedule-executor.sh    # выбирает задачи из system_schedule, запускает через RPC
│   ├── schedule-exec-once.sh
│   └── processes/              # supervisor process definitions
├── nginx/                      # reverse proxy + static + swagger UI
├── mysql/                      # кастомная сборка MySQL (ext charset, my.cnf)
├── phpmyadmin/
├── prometheus/
├── alertmanager/
├── grafana/
├── icmp-pinger/
├── trapservice/                # SNMP trap listener (Net-SNMP)
├── oxidized/
├── ttyd/                       # Web-terminal
├── eap/                        # External API Proxy
└── wca-ws/                     # WebSocket server
```

## migrations/ — Миграции ядра

```
migrations/
├── 01_init/up.sql + down.sql
├── 02_<name>/up.sql + down.sql
└── ... (60+)
```

Именуются `NN_description/` — сортируются лексикографически. Применяются командой
`wca migration:migrate all --up`. Down-миграции опциональны (могут отсутствовать).

## public/ — Публичная статика

```
public/
├── icons/          # SVG-иконки устройств
├── css/
└── img/
```

## var/ — Runtime

```
var/
├── cache/
│   └── compiled.php            # PHP-DI compiled container (авто)
├── docker/mysql/datadir/       # MySQL data (volume)
├── logs/
│   ├── system.log              # RR workers + ядро
│   ├── console.log             # wca CLI
│   ├── poller.log              # фоновый опрос
│   └── switcher-core.log       # детальный лог SNMP/console
├── openapi/openapi.yaml        # Сгенерённый OpenAPI (openapi:generate)
├── prometheus/                 # Custom rules для alertmanager
└── upload/                     # Пользовательские загрузки (иконки, attachments)
```

## examples/ и develop/

Сбор helper-скриптов для ручной отладки (walk SNMP, дамп модели, проверка адаптера
конкретного вендора). Не выполняются в prod.

## status/

Файлы-маркеры статуса контейнеров (используются в healthcheck).
