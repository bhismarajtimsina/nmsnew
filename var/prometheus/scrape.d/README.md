# Добавление внешних Prometheus Scrape Configs

Для подключения собственных метрик необходимо создать отдельный файл конфигурации в директории:

```text
/opt/wildcore-dms/var/prometheus/scrape.d/
```

Все файлы с расширением `.yml` будут автоматически загружены Prometheus.

---

## Создание нового Job

Создайте файл:

```text
/opt/wildcore-dms/var/prometheus/scrape.d/my-service.yml
```

Содержимое файла:

```yaml
scrape_configs:
  - job_name: my-service
    scrape_interval: 30s

    static_configs:
      - targets:
          - my-service:8080

        labels:
          service: my-service
          environment: production
```

---

## Несколько серверов

```yaml
scrape_configs:
  - job_name: api-cluster
    scrape_interval: 15s

    static_configs:
      - targets:
          - api-01:8080
          - api-02:8080
          - api-03:8080

        labels:
          service: api
```

---

## Использование HTTPS

```yaml
scrape_configs:
  - job_name: secure-api

    scheme: https

    static_configs:
      - targets:
          - api.example.com

    tls_config:
      insecure_skip_verify: false
```

---

## Использование Basic Auth

```yaml
scrape_configs:
  - job_name: protected-api

    basic_auth:
      username: metrics
      password: secret-password

    static_configs:
      - targets:
          - api.example.com:8080
```

---

## Важные требования

### Правильный формат файла (Prometheus v3+)

Файл **обязательно** должен содержать ключ `scrape_configs:` с вложенным списком джобов:

```yaml
scrape_configs:
  - job_name: service-1
    static_configs:
      - targets:
          - service-1:8080
```

Не используйте голый список без обёртки — это вызовет ошибку:

```yaml
# НЕПРАВИЛЬНО
- job_name: service-1
  static_configs:
    - targets:
        - service-1:8080
```

### Уникальность `job_name`

Каждый `job_name` должен быть уникальным в пределах всей инсталляции Prometheus.

Плохо:

```yaml
- job_name: api
```

```yaml
- job_name: api
```

Хорошо:

```yaml
- job_name: api-prod
```

```yaml
- job_name: api-stage
```

---

## Применение изменений

После создания или изменения файла в `scrape.d/` необходимо выполнить команду:

```bash
wca system:reload-external-apps
```

Это перезагрузит конфигурацию Prometheus. После этого проверьте, что новый job появился в интерфейсе:

```text
Status → Targets
```

или

```text
Status → Configuration
```
