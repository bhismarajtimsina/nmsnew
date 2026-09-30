# 05. Тестирование

В ядре проекта нет централизованного test-suite. Отдельные компоненты
могут приносить свои тесты под `components/<Name>/tests/`.

> **Состояние на 2026-08-17:** ни один компонент в этом дереве тестов не содержит,
> `phpunit` не входит в `composer.json`, и каталога `components/PonBoxes/`
> здесь нет. Приводимый ниже `PonBoxes` — **иллюстративный шаблон**, а не
> существующий код: копировать пути из него напрямую не получится.
>
> Фактически в дереве есть:
> - `tests/` — регрессионное покрытие security-исправлений, без зависимостей.
>   Запуск: `docker compose exec -T wca php /www/tests/run.php`. См. `tests/README.md`.
> - `src/Console/Tests/` — ручные CLI-утилиты (`TestSnmpWalk`,
>   `TestSwitcherCoreCallModules`).

## 5.1. Два уровня тестов

| Уровень | Для чего | Инструменты | Требует запущенный проект |
|---------|----------|-------------|---------------------------|
| **Integration (HTTP)** | Проверка REST-эндпоинтов компонента end-to-end — статусы, JSON-структура, CRUD-циклы, валидации ошибок, cross-component (резолв интерфейсов по bind_key) | `bash` + `curl` + `jq` | ✅ да |
| **Unit** | Проверка чистой логики (алгоритмы в `Controllers/Controller.php`, резолверы, фильтры) с замоканной инфраструктурой | `phpunit ^9` | ❌ нет |

**Integration-тесты** — минимальная и обязательная часть, они ловят 90%
реальных регрессий и не требуют установки чего-либо кроме `jq`. Для
компонента без сложной бизнес-логики можно ограничиться только ими.

**Unit-тесты** применимы там, где в `Controllers/Controller.php` есть
нетривиальный алгоритм (пороги, фильтры, state-machine) — как классификация
статусов PON-бокса. Для CRUD-обёрток — избыточно.

## 5.2. Структура `components/<Name>/tests/`

Рекомендуемый layout (зеркалит PonBoxes):

```
components/<Name>/tests/
├── README.md                    # Как запускать
├── phpunit.xml.dist             # Конфигурация PHPUnit (только для unit)
├── integration/
│   ├── lib.sh                   # Helpers: pass/fail, http_request, assert_*
│   └── run.sh                   # Основной сценарий (executable)
└── unit/
    ├── bootstrap.php            # Подтягивает autoload + stub _env()
    └── Controllers/
        └── ControllerStatusTest.php
```

## 5.3. Запуск integration-тестов (пример PonBoxes)

**Предусловия:**
- Запущен docker-stack — как минимум `wca`, `wca-nginx`, `wca-db`.
- Применены миграции компонента: `./bin/wca.sh migration:migrate <component>:* --up`.
- На хосте установлен `jq`: `apt install jq` (Ubuntu) / `brew install jq` (Mac).

**Запуск:**
```bash
# Минимум — без ONT-тестов
WCA_AUTH_KEY=<your-token> components/PonBoxes/tests/integration/run.sh

# С ONT-флоу против реального OLT
WCA_AUTH_KEY=<your-token> \
WCA_DEVICE_ID=10517 \
components/PonBoxes/tests/integration/run.sh
```

**Переменные:**

| Variable | Назначение |
|----------|------------|
| `WCA_AUTH_KEY` | `XAuthKey` пользователя с permissions `pon_boxes_*` |
| `WCA_BASE_URL` | Default `http://127.0.0.1:8088/api/v1` — измените для удалённого окружения |
| `WCA_DEVICE_ID` | ID реального OLT с ненулевыми `bind_key` в `device_interfaces`. Без него ONT-тесты skipped, а не failed |

**Exit codes:** `0` — всё зелёное, `1` — хоть один fail, `2` — misconfig (нет `jq`, auth не прошёл, API недоступно).

**Как писать свои ассерты (из `integration/lib.sh`):**
```bash
source "$(dirname "$0")/lib.sh"

BASE_URL="http://127.0.0.1:8088/api/v1/component/<name>"
AUTH_KEY="$WCA_AUTH_KEY"

section "My feature"

get "/items"
assert_status       "200"         "GET /items returns 200"
assert_json_eq      '.statusCode' '200'  "body.statusCode == 200"
assert_json_type    '.data'       'array' "body.data is array"
assert_json_contains '.error.description' 'already exists' "duplicate error wording"

post "/items" '{"name":"x"}'
NEW_ID=$(json_get '.data.id')

summary     # печатает итог + возвращает правильный exit code
```

## 5.4. Запуск unit-тестов

PHPUnit не включён в `composer.json` проекта — его нужно добавить один раз.

### Вариант A: Добавить в `composer.dev.json`

```jsonc
{
  "require-dev": {
    "phpunit/phpunit": "^9.5"
  }
}
```

Затем через installer-контейнер:
```bash
COMPOSER=composer.dev.json \
docker compose -f docker-compose.installer.yml run --rm composer
```

Запуск:
```bash
./vendor/bin/phpunit --configuration components/PonBoxes/tests/phpunit.xml.dist --testdox
```

### Вариант B: Standalone PHAR (быстрая проверка)

```bash
curl -sL https://phar.phpunit.de/phpunit-9.phar -o /tmp/phpunit.phar
chmod +x /tmp/phpunit.phar
php /tmp/phpunit.phar --configuration components/PonBoxes/tests/phpunit.xml.dist --testdox
```

### Как писать unit-тесты

**Обход проблем с DI/App:**
- `_env()` — глобальный helper, не загружается в unit-окружении. Stubbed в `unit/bootstrap.php`: возвращает значение `$default`.
- `Device::__construct` дёргает `App::getInstance()->conf(...)` — в unit-среде App не инициализирован. Создавайте модели через `ReflectionClass::newInstanceWithoutConstructor()`:
  ```php
  $rc = new \ReflectionClass(Device::class);
  $device = $rc->newInstanceWithoutConstructor();
  $device->setId(1)->setIp('10.0.0.1');
  ```

**Обход @Inject без контейнера:**
```php
private function makeController(...$mocks): Controller
{
    $rc = new \ReflectionClass(Controller::class);
    $controller = $rc->newInstanceWithoutConstructor();   // пропускаем parent __construct
    foreach (['deviceInterfaceStorage' => $mock1, 'boxStorage' => $mock2, ...] as $name => $value) {
        $p = $rc->getProperty($name);
        $p->setAccessible(true);
        $p->setValue($controller, $value);
    }
    return $controller;
}
```

**Что удобно тестировать юнитом:**
- State-machine переходы (статусы, алгоритмы классификации)
- Фильтры и пороговые условия (где легко ошибиться в границах)
- Резолверы (как `AddOnt::resolveInterface`)
- Парсеры/форматтеры ответов

**Что НЕ стоит тестировать юнитом в этом проекте:**
- CRUD-action-классы — они по сути 5-10 строк над `$storage->add/update/delete`
- Storage-классы — слишком сильно зависят от PDO, integration-тесты покрывают это лучше
- OpenAPI-аннотации — их парсинг уже проверяется при запуске `Generator::scan()`

## 5.5. CI

Минимальный job для GitHub Actions:

```yaml
jobs:
  pon-boxes-tests:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Up stack
        run: docker compose up -d wca wca-nginx wca-db wca-memcached wca-redis
      - name: Wait for API
        run: until curl -sf http://127.0.0.1:8088/api/v1/public/defaults; do sleep 2; done
      - name: Apply migrations
        run: ./bin/wca.sh migration:migrate all --up
      - name: Install jq
        run: sudo apt-get install -y jq
      - name: Integration tests
        env:
          WCA_AUTH_KEY: ${{ secrets.WCA_CI_TOKEN }}
        run: components/PonBoxes/tests/integration/run.sh
      # Unit tests — only after PHPUnit is in composer.dev.json:
      # - name: Unit tests
      #   run: ./vendor/bin/phpunit --configuration components/PonBoxes/tests/phpunit.xml.dist
```

## 5.6. Чеклист для нового компонента

При добавлении нового компонента, поставляющего REST API:

- [ ] `tests/integration/lib.sh` — скопировать у PonBoxes, shared helper'ы не зависят от компонента
- [ ] `tests/integration/run.sh` — сценарии для своих эндпоинтов (read / CRUD / валидации)
- [ ] `tests/README.md` — как запускать, какие env-vars, что skipped без реальных данных
- [ ] Опционально `tests/unit/` + `phpunit.xml.dist`, если есть нетривиальная логика в Controller
- [ ] Обновить `docs/05-testing.md` в части списка компонентов с тестами (если будет поддерживаться индекс)
