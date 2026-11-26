# TimeCapsule Events

## capsule.sealed

Событие публикуется приложением в SNS-тему `timecapsule-dev-events` после успешного создания капсулы в PostgreSQL.

### Формат сообщения

```json
{
  "version": 1,
  "type": "capsule.sealed",
  "event_id": "capsule.sealed:42",
  "capsule_id": 42
}
```

| Поле | Тип | Описание |
|---|---|---|
| version | Integer | Версия схемы события |
| type | String | Тип события |
| event_id | String | Уникальный ключ идемпотентности |
| capsule_id | Integer | Идентификатор капсулы в PostgreSQL |

### Обработка

SNS доставляет событие в очередь `timecapsule-dev-mail`. Lambda-функция `timecapsule-dev-mailer` получает сообщение, загружает данные капсулы из PostgreSQL, имитирует задержку отправки письма и записывает уведомление в таблицу `notifications`.

### Идемпотентность

Идентификатор события формируется из типа события и идентификатора капсулы. Повторная доставка события не должна создавать дополнительное уведомление.

### Безопасность

Событие не содержит текста капсулы, вложений, адресов электронной почты, паролей или других конфиденциальных данных. Необходимая информация загружается обработчиком непосредственно из PostgreSQL.

## capsule.opened

Событие формируется Lambda-функцией `timecapsule-dev-opener` при открытии капсул по расписанию EventBridge Scheduler.

Результат асинхронного вызова передаётся в SNS через Lambda Destination.

### Формат сообщения

```json
{
  "responsePayload": {
    "type": "capsule.opened",
    "opened_count": 1,
    "events": [
      {
        "version": 1,
        "type": "capsule.opened",
        "event_id": "capsule.opened:42",
        "capsule_id": 42
      }
    ]
  }
}
```

Показана только значимая часть invocation record.

### Обработка

Lambda Mailer извлекает события из `responsePayload.events` и создаёт уведомления в PostgreSQL. Уникальный индекс по `event_id` защищает от повторной обработки.

Подписка Email использует фильтрацию по телу сообщения и пропускает только результаты с `opened_count > 0`.

Если открытых капсул нет, массив `events` остаётся пустым и уведомления не создаются.