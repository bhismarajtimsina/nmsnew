# 01. Составные части бэкенда и локальный запуск

## 1.1. Что такое Support API

Унифицированный REST/CLI-backend для ISP DMS — управление разновендорным сетевым
оборудованием (OLT/ONT, свитчи ZTE, Huawei, MikroTik и т.д.) через SNMP,
Telnet/SSH и vendor API. Построен вокруг плагинной архитектуры: ядро (`src/`)
+ 30+ компонентов в `components/`.

## 1.2. Составные части (сервисы)

Проект поднимается как docker-compose stack. Каждый сервис решает свою задачу.

| Сервис | Образ | Роль | Обязательный |
|--------|-------|------|--------------|
| `wca` | `meklis/wca-roadrunner:<ver>` | **Main API.** PHP 7.4 + RoadRunner 2.x worker pool + Slim 4. Обслуживает `/api/v1/*`. | ✅ |
| `wca-ws` | `meklis/wca-ws:<ver>` | WebSocket-сервер для push-уведомлений и realtime-событий. | ✅ |
| `wca-nginx` | nginx 1.21 | Reverse proxy, отдача статики, Swagger UI, терминация HTTP. | ✅ |
| `wca-db` | MySQL 8 | Основное хранилище (60+ миграций ядра + миграции компонентов). | ✅ |
| `wca-memcached` | memcached | Сессии, query cache, временное кэширование SwitcherCore. | ✅ |
| `wca-redis` | redis | Event bus (pub/sub), координация воркеров, RPC. | ✅ |
| `wca-schedule-executor` | `meklis/wca-schedule-executor:<ver>` | **Cron-executor.** Supervisor RPC, выполняет периодические задачи из таблицы `system_schedule`. | ✅ |
| `wca-icmp-pinger` | `meklis/wca-icmp-pinger:<ver>` | Быстрые ICMP-проверки доступности устройств. | опционально |
| `wca-trapservice` | `meklis/wca-traplistener:<ver>` | SNMP trap listener → публикует события в Redis. | опционально |
| `wca-ttyd` | `meklis/wca-ttyd:<ver>` | Web-терминал (браузер → telnet/ssh на девайсы). | опционально |
| `wca-oxidized` | `meklis/wca-oxidized:<ver>` | Конфиг-бэкапы сетевых устройств (git history). | опционально |
| `wca-eap` | `meklis/wca-eap:<ver>` | External API Proxy для внешних интеграций. | опционально |
| `prometheus` | prom/prometheus | Сбор метрик с `/metrics` и пушей из ядра. | рекомендовано |
| `alertmanager` | prom/alertmanager | Маршрутизация алертов (правила в БД). | рекомендовано |
| `grafana` | grafana/grafana | Дашборды поверх Prometheus. | рекомендовано |
| `wca-db-admin` (phpmyadmin) | phpmyadmin | Админка MySQL в браузере. | dev-only |
| `wca-swagger-ui` | swaggerapi/swagger-ui | UI для `var/openapi/openapi.yaml`. | dev-only |

Все сервисы общаются в сети `wca-network` (bridge), внешний трафик идёт через `wca-nginx`.

## 1.3. HTTP-флоу запроса

```
Client
  └─→ wca-nginx (:8088)                    # TLS/reverse proxy, static
       └─→ wca (RoadRunner :8080)          # worker pool (RR_NUM_WORKERS воркеров)
            └─→ app/server.php             # entrypoint воркера
                 └─→ app/init.php          # bootstrap
                      └─→ App::init()      # DI контейнер (PHP-DI)
                           └─→ Slim router # app/routes.php
                                └─→ Action # src/Api/Actions/** или components/*/Api/**
                                     └─→ Service → Storage → PDO → MySQL
```

RoadRunner держит PHP-воркеры долгоживущими — **состояние в статике опасно**, каждый
запрос не получает свежий процесс.

## 1.4. CLI-флоу

```
bin/wca.sh  →  docker exec wca wca <cmd>
                                 │
                                 ├─→ console (php script в корне репо)
                                 │    └─→ app/init.php → Symfony Console
                                 │         └─→ команды из src/Console/
                                 │         └─→ команды из components/*/Console/
```

Регистрация команд:
- Ядро: `config/console.yml`
- Компоненты: ключ `console` в `config.php` каждого компонента, имя префиксуется `<component_name>:<command>` автоматически.

## 1.5. Зависимости для локального запуска

На хосте:
- Docker ≥ 20, docker-compose v2
- Свободные порты (по умолчанию): `8088` (API), `33306` (MySQL — маппинг на 3306 внутри)
- Файл `/etc/timezone` (читается контейнерами для синхронизации TZ)
- 2+ GB RAM для MySQL `innodb_buffer_pool_size=1G`

Альтернатива bare-metal описана в `../README.md`. Рекомендуется Docker.

## 1.6. Пошаговый локальный запуск

### Шаг 1. Клонирование и .env

```bash
git clone <repo-url> support-api
cd support-api
cp .env-example .env
```

Минимально отредактируйте в `.env`:
```
NGINX_EXPOSE=0.0.0.0:8088
MYSQL_EXPOSE=127.0.0.1:33306
DATABASE_PASSWD=<придумать>
ENVIRONMENT=DEVELOPMENT
LOG_LEVEL=DEBUG
```

### Шаг 2. Установка PHP-зависимостей

Через контейнер-установщик (не требует PHP на хосте):
```bash
docker compose -f docker-compose.installer.yml run --rm composer
```
Это положит `vendor/` в корень проекта, примонтированный в `wca` как `/www/vendor`.

### Шаг 3. Подъём сервисов

Разработка:
```bash
docker compose -f docker-compose.dev.yml up -d
```
(В `docker-compose.dev.yml` код монтируется с хоста, поэтому изменения в `src/` /
`components/` подхватываются без пересборки.)

Production-like:
```bash
docker compose up -d
```

### Шаг 4. Миграции ядра и компонентов

```bash
# Ядро (таблицы users, devices, events, poller_*, и т.д.)
./bin/wca.sh migration:migrate all --up

# После этого доступны команды компонентов:
./bin/wca.sh list
```

Установщик компонента подтягивается автоматически при первом вызове
`ComponentInjector` через `InstallerAbstract::install()`, который исполняет
миграции из `components/<Name>/migrations/`. Принудительный re-run:
```bash
./bin/wca.sh migration:migrate <component_name>:* --up
```

### Шаг 5. Создание администратора

```bash
./bin/wca.sh user:create-admin --login=admin --password=admin
```

### Шаг 6. Проверка

- Swagger UI: `http://localhost:8088/swagger/`
- API healthcheck: `GET http://localhost:8088/api/v1/public/info`
- phpMyAdmin: `http://localhost:8088/phpmyadmin` (если собран)
- Grafana: `http://localhost:3000` (если exposed)

### Шаг 7. Логи

```bash
docker compose logs -f wca              # API
docker compose logs -f wca-schedule-executor   # cron-задачи
tail -f var/logs/system.log             # файловый лог
tail -f var/logs/console.log            # вывод ./wca
```

## 1.7. Типовые команды разработчика

```bash
# Консоль внутри контейнера
./bin/wca.sh                             # список команд
./bin/wca.sh <cmd>                       # запуск команды
./bin/wca.sh <cmd> -v                    # подробный вывод
./bin/wca.sh <cmd> -vvv                  # debug

# Перезагрузка воркеров после изменений
docker compose restart wca

# Полная чистка кэшей
./bin/wca.sh cache:clear

# Пересоздать OpenAPI-yaml
./bin/wca.sh openapi:generate

# Применить миграции одного компонента
./bin/wca.sh migration:migrate pon_boxes:* --up
./bin/wca.sh migration:migrate pon_boxes:* --down   # откат

# Войти в контейнер
docker exec -it wca bash
```

## 1.8. Частые проблемы

| Симптом | Причина / Решение |
|---------|-------------------|
| `500` на всех запросах сразу после старта | Миграции не применены — запустите `migration:migrate all --up`. |
| Изменения в `src/` не видны | В dev-compose код монтируется, но RoadRunner кэширует классы — сделайте `docker compose restart wca`. Autoloader обновляется без рестарта только для новых файлов. |
| `Class ... not found` после нового файла | `docker compose exec wca composer dump-autoload` либо рестарт `wca`. |
| MySQL не поднимается | Проверьте свободный порт `33306` и достаточный `innodb_buffer_pool_size`. |
| Permission denied для `var/` | `chmod -R 777 var/` (есть legacy-код, которому нужны полные права). |
| Команды `wca` зависают в pty | Используйте `bin/wca-no-pty.sh` вместо `bin/wca.sh`. |
