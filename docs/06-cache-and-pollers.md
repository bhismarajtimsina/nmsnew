# 06. Кэш и опросчики

## 6.1. SWC Cache (SwitcherCore Cache)

### Что это

SWC cache — слой кэширования ответов от `SwitcherCore` (библиотека опроса оборудования).
Хранится в Memcache. Ключ формируется по схеме:

```
SWC:{device_id}:{module}:{status}:{sha1(json(arguments))}
```

Пример: `SWC:42:sfp_optical:SUCCESS:da39a3ee...`

Реализация: `src/SwitcherCore/CacheSystem/MemCacheSystem.php`.

### Когда пишется

SWC cache пишется **только** внутри `SwitcherCore::fromDevice()` — автоматически
для каждого ответа, включая ответы с ошибкой:

```php
// SwitcherCore.php
foreach ($responses->getAllResponses() as $response) {
    $this->swcCache->write($response);                          // ← полный ответ
    $this->writeCacheWithReponsesSplittedByInterface($response); // ← разбивка по интерфейсам
}
```

Ни один опросчик и ни один контроллер **не пишет** в SWC cache напрямую.
Это всегда побочный эффект вызова `fromDevice()`.

### Разбивка по интерфейсам (splitting)

Если в конфиге `switcher_core.enable_splitting = true` и модуль входит в
`switcher_core.split_by_interface_methods`, то после записи полного ответа
`writeCacheWithReponsesSplittedByInterface()` автоматически записывает
отдельные записи для каждого интерфейса из ответа.

Это позволяет API читать данные одного порта через `fromCache(['interface' => X])`
даже если опросчик собирал данные по всему устройству сразу.

Модули с включённым splitting (из `config/global.php`):
`interface_counters`, `pon_onts_serial`, `pon_onts_mac_addr`, `pon_onts_optical`,
`pon_onts_status`, `pon_onts_vendor`, `interface_descriptions`, `fdb`,
`pon_onts_reasons`, `link_info`, `vlans_by_port`, `errors`, `interfaces_list`,
`rmon`, `sfp_optical`, `sfp_media`.

### Три способа читать данные

| Метод | Описание |
|---|---|
| `fromDevice(reqs)` | Идти на устройство, результат записать в SWC cache |
| `fromCache(reqs)` | Читать из SWC cache; если кэш просрочен — пойти на устройство |
| `fromStore(reqs)` | Читать из SWC cache без проверки срока (игнорирует `cache_actualize_timeout_sec`) |

Свежесть кэша контролируется параметром `switcher_core.cache_actualize_timeout_sec`
(по умолчанию 300 с). `fromStore` используется когда нужны последние известные данные
вне зависимости от их возраста.

---

## 6.2. Опросчики (Pollers)

### Архитектура

```
PollerProcessor
  │
  ├─ getPollersList(device)    → список актуальных опросчиков по интервалу
  │
  └─ foreach poller:
       └─ PollerAbstract::poll(device, controller)
            ├─ controller->someMethod()          → fromDevice() → SWC cache
            └─ sync(device, data)                → Prometheus
```

Каждый опросчик — класс, реализующий `PollerInterface` и наследующий `PollerAbstract`.
Маппинг «имя поллера → класс» задан в `PollerProcessor::$configuration`.

### Жизненный цикл вызова `poll()`

1. `PollerProcessor` создаёт запись в `poller_processing` (статус = IN_PROGRESS).
2. Вызывает `walker->poll($device, $controller)`.
3. Внутри `poll()` контроллер опрашивает устройство через `callCore(..., 'device')` →
   `SwitcherCore::fromDevice()` → **SWC cache автоматически обновляется**.
4. Данные передаются в `sync()` → **Prometheus обновляется**.
5. `PollerProcessor` обновляет запись: статус SUCCESS / FAILED + время.

Сам опросчик `notifyPolledNow()` из `poll()` **не вызывает** — это задача
`PollerProcessor`. `notifyPolledNow()` вызывается только из `setManual()`,
когда данные пришли через API-путь (`ResponseToPollerWriter`).

### Два пути поступления данных в опросчик

#### Путь 1: Плановый опрос через `PollerProcessor`

Запускается крон-процессом / демоном.

```
PollerProcessor::poll()
  └─ SfpOpticalStrengthHistory::poll(device, controller)
       ├─ controller->sfpOpticalInfo()           → fromDevice() → SWC cache ✅
       └─ sync(device, data)                     → Prometheus ✅
```

Поллер помечается выполненным самим `PollerProcessor` (запись в БД).

#### Путь 2: Данные из API-вызова (`ResponseToPollerWriter`)

Когда пользователь открывает страницу устройства, API собирает данные через
`fromDevice()` и сразу отдаёт их в `ResponseToPollerWriter::process()`, который
«прокармливает» опросчики свежими данными без лишнего запроса к оборудованию.

```
Controller::getInterfaceFullInfo()
  └─ SwitcherCore::fromDevice(sfp_optical, [interface => X])
       └─ SWC cache ✅ (пишется автоматически)
  └─ ResponseToPollerWriter::process(responses)
       └─ sfpOpticalInfo->setManualByInterface(device, data)
            └─ sync(device, data)               → Prometheus ✅
            (notifyPolledNow не вызывается — запись в БД не обновляется)
```

`setManual()` (вызов без interface-аргумента) — вызывает `sync()` + `notifyPolledNow()`,
т.е. обновляет статус поллера в БД.

`setManualByInterface()` (вызов с данными по конкретному интерфейсу) — только `sync()`.

### Что пишет каждый компонент

| Компонент | SWC cache | Prometheus | БД (`poller_processing`) |
|---|---|---|---|
| `SwitcherCore::fromDevice()` | ✅ всегда | — | — |
| `PollerAbstract::sync()` | — | ✅ | — |
| `PollerProcessor` (обёртка) | — | — | ✅ SUCCESS/FAILED |
| `PollerAbstract::notifyPolledNow()` | — | — | ✅ SUCCESS (только из `setManual`) |

### Выбор опросчиков для запуска (`getPollersList`)

Список активных опросчиков берётся из (в порядке приоритета):
1. `device.params.poller_config`
2. `model.params.poller_config`
3. `model.pollers`

Формат `poller_config`:
```json
{
  "sfp_optical_strength": { "enabled": true, "interval": 300 },
  "counters":             { "enabled": true, "interval": 60 }
}
```

`interval` — минимальный интервал в секундах между запусками. Если последний запуск
был менее `interval` секунд назад, поллер пропускается.

---

## 6.3. Опросчик `sfp_optical_strength`

### Класс

`src/Infrastructure/Poller/Pollers/SfpOpticalStrengthHistory.php`

Реализует `PollerInterface`, требует от контроллера `PollerSfpOpticalStrengthInterface`
(метод `sfpOpticalInfo()`).

### Почему SWC cache может не обновляться

**Единственная причина** — ранний возврат `[]` в методе `sfpOpticalInfo()` контроллера:

```php
// SwitchesController.php, AbstractProcessor.php
public function sfpOpticalInfo($from = 'device')
{
    if (!in_array('sfp_optical', $this->getSupportedModules())) {
        return [];  // ← callCore не вызывается → fromDevice не вызывается → кэш не пишется
    }
    $resp = $this->callCore('sfp_optical', [], $from);
    ...
}
```

Если модуль `sfp_optical` **не входит в список поддерживаемых модулей устройства**
(поле `meta.modules` из ответа модуля `system`), опросчик тихо завершается с пустым
результатом. `PollerProcessor` при этом помечает его как SUCCESS, что вводит в
заблуждение.

Контроллеры `RouterController` и `RouterOS` этой проверки не имеют и всегда вызывают
`callCore`.

### Особенность OLT-контроллеров (`AbstractProcessor`)

`AbstractProcessor::processRequests()` при вызове `from = 'device'` сразу прогоняет
ответы через `ResponseToPollerWriter::process()`:

```php
case 'device':
    $responses = $this->core->fromDevice($requests, $concurrency);
    $this->responseToPollerWrapper->process($responses); // ← sync() #1
    break;
```

Это означает, что `sync()` вызывается дважды: один раз внутри
`AbstractProcessor::sfpOpticalInfo()` и второй раз из `SfpOpticalStrengthHistory::poll()`.
Prometheus-метрики записываются дважды, хотя второй вызов перезаписывает первый
(idempotent), поэтому видимых последствий нет.

---

## 6.4. Известные особенности

### `writeCacheWithReponsesSplittedByInterface` — неверная проверка

```php
// SwitcherCore.php
if (in_array('interface', $data->getArguments())) return;
```

Проверяет наличие строки `'interface'` среди **значений** массива аргументов, а не
среди **ключей**. Фактически никогда не срабатывает. Задуманное поведение — пропускать
splitting для уже-per-interface ответов — не реализовано. На работу кэша это не влияет
(splitting просто делается лишний раз для одного интерфейса), но код вводит в
заблуждение.
