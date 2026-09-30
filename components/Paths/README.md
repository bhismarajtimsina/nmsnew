# Paths — мониторинг транспортных маршрутов

Компонент следит за многосегментными маршрутами (Kathmandu → Belbari → Pathari)
и за **группами резервирования**: если до Pathari идут два маршрута и один упал,
сервис жив, но резерва больше нет. Именно это состояние (`unprotected`) обычно
никто не замечает — до момента, когда падает второй маршрут.

## Модель

| Таблица | Назначение |
|---------|------------|
| `c_paths` | Маршрут: имя, `group_key`, приоритет, две конечные точки |
| `c_path_segments` | Упорядоченные участки, каждый ссылается на существующую строку `c_links` |
| `c_path_states` | Текущее состояние (денормализовано, чтобы карта читалась одним запросом) |

Маршруты с одинаковым `group_key` резервируют друг друга. Приоритет (`priority`)
задаёт основной/резервный — меньше значение, выше приоритет.

Сегменты **ссылаются на `c_links`**, а не дублируют топологию: связи,
обнаруженные по LLDP/FDB, переиспользуются как есть.

## Как считается состояние

Источник доступности — компонент `Pinger` (отрицательная задержка = устройство
не ответило). Своего опроса компонент не добавляет.

| Состояние маршрута | Когда |
|--------------------|-------|
| `up` | все узлы отвечают, задержки в норме |
| `degraded` | все узлы отвечают, но задержка выше `PATHS_DEGRADED_LATENCY_MS` |
| `down` | хотя бы один узел не отвечает |
| `unknown` | нет сегментов, удалён link, или какой-то узел ни разу не опрошен |

`unknown` намеренно **не** трактуется как `up` — иначе неопрошенный участок
выглядел бы здоровым.

| Состояние группы | Когда |
|------------------|-------|
| `protected` | ≥2 маршрута, все рабочие |
| `unprotected` | часть рабочих, часть нет — трафик идёт, резерва нет |
| `outage` | ни одного рабочего маршрута |
| `up` | группа из одного маршрута (резервировать нечего) |

Группа из одного маршрута **никогда** не показывается как `unprotected` — иначе
такой алерт горел бы постоянно.

## Настройка маршрутов Kathmandu → Pathari

Предполагается, что устройства заведены, у них проставлены `coordinates`
(`{"lat": ..., "lon": ...}`), и связи между ними уже есть в `c_links`.

```bash
# 1. Основной маршрут через Belbari
curl -X POST http://<host>/api/v1/component/paths \
  -H 'X-Auth-Key: <key>' -H 'Content-Type: application/json' \
  -d '{
        "name": "Kathmandu → Belbari → Pathari",
        "group_key": "ktm-pathari",
        "priority": 10,
        "endpoint_a_id": <id KTM>,
        "endpoint_b_id": <id Pathari>,
        "link_ids": [<KTM→Belbari>, <Belbari→Pathari>]
      }'

# 2. Резервный маршрут через Damak
curl -X POST http://<host>/api/v1/component/paths \
  -H 'X-Auth-Key: <key>' -H 'Content-Type: application/json' \
  -d '{
        "name": "Kathmandu → Damak → Pathari",
        "group_key": "ktm-pathari",
        "priority": 20,
        "endpoint_a_id": <id KTM>,
        "endpoint_b_id": <id Pathari>,
        "link_ids": [<KTM→Damak>, <Damak→Pathari>]
      }'
```

Одинаковый `group_key` — это и есть то, что связывает их в пару с резервом.

Проверка:

```bash
./console paths:calc-state
```

## API

| Метод | Путь | Назначение |
|-------|------|------------|
| `GET` | `/component/paths/map` | Группы с маршрутами, состоянием и координатами точек — для карты |
| `GET` | `/component/paths/groups` | Только состояния групп, без геометрии — для дашбордов |
| `GET` | `/component/paths` | Список маршрутов |
| `POST` | `/component/paths` | Создать маршрут |
| `GET,PUT,DELETE` | `/component/paths/{id}` | Чтение/изменение/удаление |
| `PUT` | `/component/paths/{id}/segments` | Заменить весь упорядоченный список участков |

Права: `paths_view` (чтение), `paths_edit` (изменение).

## Метрики и алерты

Команда `paths:calc-state` (по расписанию `*/1 * * * *`) экспортирует:

| Метрика | Смысл |
|---------|-------|
| `path_state` | 1=up, 0.5=degraded, 0=down, -1=unknown |
| `path_segments_down` | сколько участков маршрута лежит |
| `path_group_protected` | 1, если все маршруты группы рабочие |
| `path_group_up_count` | сколько маршрутов группы рабочие |
| `path_group_total` | всего маршрутов в группе |

Правила Alertmanager ставятся миграцией: `path_down`, `path_degraded`,
`path_group_unprotected`, `path_group_outage`. Доставка — через компонент
`Notifications` (Telegram/Email).

## Realtime на карте

При **смене** состояния маршрута компонент шлёт событие `path:state-changed`
через `EventObserverStorage` → Redis → WebSocket. Канал `event:path:*` разрешён
в `config/ws-permissions.yml`.

Полезная нагрузка:

```json
{
  "path_id": 1,
  "name": "Kathmandu → Belbari → Pathari",
  "group_key": "ktm-pathari",
  "state": "down",
  "previous_state": "up",
  "endpoint_a": "KTM-CORE",
  "endpoint_b": "PATHARI-AGG",
  "changed_at": "2026-08-17 18:55:00"
}
```

SPA подписывается на `event:path:*` и перерисовывает только затронутые линии —
без поллинга.

### Отрисовка

`GET /component/paths/map` возвращает для каждого маршрута `points` —
упорядоченный список точек с `lat`/`lon`. Рисуется как polyline, цвет по
состоянию. У точек без координат стоит `has_coordinates: false` — их нужно
подсветить, а не молча соединять линией мимо реального маршрута.

Группу удобно рисовать двумя параллельными линиями: «одна красная, одна зелёная»
читается как «резерва нет» с одного взгляда.

## Параметры

| Параметр | По умолчанию | Смысл |
|----------|--------------|-------|
| `PATHS_DEGRADED_LATENCY_MS` | 150 | Порог задержки для `degraded` |
| `PATHS_STATE_METRIC_TTL_SEC` | 300 | TTL метрик в экспортере |

## Тесты

Логика состояний покрыта в `tests/Unit/PathStateCalculatorTest.php`:

```bash
docker compose exec -T wca php /www/tests/run.php
```
