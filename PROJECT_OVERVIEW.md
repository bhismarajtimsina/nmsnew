# Support API (WCA) — Project Overview

## Назначение
Backend для системы управления сетевым оборудованием (DMS). Унифицированный REST API для мониторинга и конфигурирования разновендорного оборудования ISP (OLT/ONT, свитчи ZTE, Huawei, MikroTik и т.д.) через SNMP, Telnet/SSH и vendor API.

**Домен:** телеком-инфраструктура, ISP operations, администрирование сетей.

---

## Технологический стек

| Компонент | Технология |
|-----------|------------|
| Язык | PHP 7.4 |
| HTTP framework | Slim 4 |
| Application server | RoadRunner 2.x (worker pool, не FPM) |
| DI | PHP-DI 6.3 |
| База данных | MySQL (PDO, 60+ миграций) |
| Кэш | Memcached (session/query) + Redis (event bus, pub/sub) |
| Логирование | Monolog 2.2 |
| Метрики | Prometheus client |
| API docs | Swagger/OpenAPI 3.0 (`zircote/swagger-php`) |
| Работа с девайсами | `meklis/switcher-core` (внутренняя lib) |
| Оркестрация | Docker Compose |

---

## Структура репозитория

```
app/                 # Bootstrap (init.php, server.php, routes.php, dependencies.php)
src/
  Api/Actions/       # REST handlers (~114 классов)
  Console/           # CLI команды (~64)
  Infrastructure/    # Кэш, поллер, метрики, ComponentInjector
  Models/            # Data models
  OpenApi/           # Swagger-аннотации
  Services/          # Бизнес-логика
  Storage/           # Репозитории
  SwitcherCore/      # Адаптеры протоколов
components/          # 29 плагин-модулей (Analytics, Events, Links, Notifications...)
config/              # global.php, rules.yml (RBAC), console.yml, env-params.yml
migrations/          # DB schema
docker/              # Контейнеры и их конфиги
public/              # Статика
var/                 # Runtime-файлы (openapi.yaml, кэш, логи)
```

---

## Точки входа

### HTTP
```
Client → nginx → RoadRunner (8080) → worker pool
       → app/server.php → app/init.php → App.php::init() → Slim router
```

- **Routes:** `app/routes.php`, группа `/api/v1/...`
- **DI definitions:** `app/dependencies.php`
- **Middleware:** auth → permission check

### CLI
```
./console <command>
```
- Symfony Console Application
- Регистрация команд через `config/console.yml`

---

## REST API

- **Base:** `/api/v1`
- **Public:** `/api/v1/public/*` (defaults, translations)
- **Auth:** `POST /api/v1/auth`, `POST /api/v1/app-auth`
- **Private (RBAC):**
  - `/dashboard/*` — виджеты, шаблоны
  - `/device/*`, `/device-group/*` — инвентарь и состояние
  - `/user*` — пользователи, роли, permissions
  - `/component/{name}/*` — роуты плагин-модулей
  - `/switcher-core/{storage}/{module}/{device_id}` — прямое исполнение команд на девайсе
  - `/logs/*` — системные, девайсовые, поллеровые логи
  - `/maps/*` — геовизуализация
  - `/poller/*` — управление фоновым опросом

### Authentication
- Сессии + API key (`XAuthKey` header)
- TTL через `API_KEY_EXPIRATION`
- 2FA через TOTP (`ConnectUser2faAction`, OTPHP lib)
- Strict mode (`API_STRICT_RULES`): требует явный permission для всех маршрутов

### RBAC
- Правила в `config/rules.yml` — regex-маршрут → требуемый permission

### Документация
- Генерится из `@OA\*` аннотаций
- Результат: `var/openapi/openapi.yaml`
- UI — через nginx

---

## Ключевые архитектурные паттерны

### 1. Компонентная плагин-система
29 модулей в `components/` динамически подключают routes, commands, event listeners через `ComponentInjector`. Добавление функциональности не требует правок ядра.

### 2. Event-driven
`EventObserverStorage` + Redis pub/sub для async-координации (напр. `system:reload-web-workers`).

### 3. Poller System
Фоновый опрос устройств с concurrency control, кэшированием статистики интерфейсов.

### 4. SwitcherCore Abstraction
Единый API поверх SNMP / console (Telnet/SSH) / MikroTik API — скрывает разницу вендоров.

### 5. Многоуровневый слой данных
`Action → Service → Storage → Database`

---

## Конфигурация и секреты

### Загрузка
- `.env` (gitignored), шаблон в `.env-example`
- Через `vlucas/phpdotenv`
- Merge в `config/global.php`

### Ключевые env-переменные
| Группа | Переменные |
|--------|------------|
| Database | `DATABASE_URL`, `DATABASE_USER`, `DATABASE_PASSWD` |
| Cache | `MEMCACHE_SERVER`, `REDIS_HOST`, `REDIS_PORT` |
| Device polling | `SWC_SNMP_*`, `SWC_CONSOLE_*`, `POLLER_*` |
| RoadRunner | `RR_NUM_WORKERS`, `RR_MAX_WORKER_MEMORY` |
| Logging | `LOG_LEVEL`, `LOG_FILES_PATH` |
| Security | `API_KEY_EXPIRATION`, `API_STRICT_RULES`, `RATE_LIMITER_*`, `PROXY_ENABLED` |
| Monitoring | `PROMETHEUS_URL`, `ALERTMANAGER_URL` |
| Integrations | `TRAP_SERVICE_ENABLED`, `SEARCH2_ENABLED` |

---

## Docker-сервисы

| Сервис | Назначение | Образ |
|--------|------------|-------|
| `wca` | Main API (RoadRunner) | `meklis/wca-roadrunner:0.30.39` |
| `wca-ws` | WebSocket server | `meklis/wca-ws:0.30.39` |
| `wca-nginx` | Reverse proxy, static, Swagger UI | nginx 1.21 |
| `wca-db` | MySQL | |
| `wca-memcached` | Session/query cache | |
| `wca-redis` | Event queue, pub/sub | |
| `wca-schedule-executor` | Supervisor RPC, cron | `meklis/wca-schedule-executor:0.30.39` |
| `wca-icmp-pinger` | Device reachability | |
| `wca-trapservice` | SNMP trap listener | |
| `wca-ttyd` | Web-терминал для devices | |
| `wca-oxidized` | Configuration backup | |
| `prometheus` | Metrics collection | |
| `alertmanager` | Alert routing | |
| `grafana` | Dashboards | |
| `phpmyadmin` | DB admin UI | |

---

## Тестирование

- Нет централизованного test suite (phpunit/pest) и нет CI-конфигурации
- Ни один компонент в `components/` не содержит каталога `tests/`
- В `src/Console/Tests/` — команды для SNMP walk, ручного тестирования SwitcherCore модулей
- В `/examples/` — helper-скрипты для локальной отладки (каталога `/develop/` в дереве нет)
- `docs/05-testing.md` описывает **целевой** подход, а не существующий код

---

## Примечательное / особенности

1. **RoadRunner вместо PHP-FPM** — true concurrency, RPC-координация. Важно: воркеры живут долго, внимательно с глобальным состоянием.
2. **Активная работа над OpenAPI** — аннотации `@OA\*` покрывают большую часть `src/Api/Actions/`, генерация в `var/openapi/openapi.yaml` заведена в расписание (`openapi_doc`, `@reboot`). Ссылки на конкретные ветки убраны: этот каталог — развёрнутая установка, а не git-checkout.
3. **Добавление nginx** в compose — переход на контейнеризованный reverse proxy.
4. **Metrics-first design** — `/metrics` endpoint с gzip, встроенный Prometheus exporter.
5. **Плагинная архитектура** — крайне важна для расширения без модификации ядра.

---

## Рекомендации по навигации

| Задача | Куда смотреть |
|--------|---------------|
| Новый/изменить API endpoint | `src/Api/Actions/`, `src/OpenApi/` |
| Работа с девайсами | `src/SwitcherCore/`, vendor `meklis/switcher-core` |
| Работа с модулями | `components/<name>/` + `ComponentInjector` |
| Изменить permissions | `config/rules.yml` |
| Новый фоновый процесс | учесть `schedule-executor` (supervisor RPC) и `poller` |
| DB изменения | `migrations/` (новая миграция — новый файл) |
| CLI команда | `src/Console/` + регистрация в `config/console.yml` |

---

## Ограничения при разработке

- **PHP 7.4** (не 8.x) — нет union types, named arguments, enums, readonly
- **RoadRunner worker-модель** — stateless воркеры, осторожно с singleton state
- **Legacy-код присутствует** — напр., маркер `// Гребанный быдлокод` в `InstallerAbstract`
