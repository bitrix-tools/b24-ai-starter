# Инструкция для ИИ: Создание своего робота в Bitrix24

## Описание
Эта инструкция описывает, как создать собственного робота для бизнес-процессов в Bitrix24. Роботы позволяют автоматизировать процессы в CRM, задачах и смарт-процессах.

## Основные понятия

### Что такое роботы Bitrix24

**Роботы** — это специальные действия автоматизации, которые пользователи могут размещать на стадиях воронок CRM, в умных сценариях или в дизайнере бизнес-процессов.

Роботы позволяют:
- Отправлять данные во внешние системы
- Получать данные из внешних API
- Выполнять сложные бизнес-операции
- Интегрировать Bitrix24 с другими сервисами

### Разница между роботами и действиями бизнес-процессов

- **Роботы** используются в CRM, задачах, умных сценариях
- **Действия бизнес-процессов** используются в дизайнере бизнес-процессов
- Роботы также доступны в дизайнере бизнес-процессов
- **Рекомендация**: создавайте роботов, а не действия

Документация: https://github.com/bitrix24/b24restdocs/blob/main/api-reference/bizproc/index.md

## Шаг 1: Подготовка

### 1.1. Создайте приложение

Роботы работают только в контексте приложения. Создайте локальное приложение:

1. Зайдите в **Разработчикам → Другое → Локальные приложения**
2. Нажмите **Добавить**
3. Укажите:
   - Название приложения
   - URL приложения
   - Права доступа: обязательно включите **bizproc** (бизнес-процессы)

Документация: https://github.com/bitrix24/b24restdocs/blob/main/local-integrations/local-apps.md

### 1.2. Определите функцию робота

Примеры задач для робота:
- Отправка данных в CRM стороннего сервиса
- Получение курса валют из внешнего API
- Расчет скидки по сложной формуле
- Отправка SMS или email через внешний сервис
- Создание документа в Google Docs

## Шаг 2: Регистрация робота

### 2.1. Метод bizproc.robot.add

Используйте метод `bizproc.robot.add` для регистрации робота.

Документация: https://github.com/bitrix24/b24restdocs/blob/main/api-reference/bizproc/bizproc-robot/bizproc-robot-add.md

### 2.2. Основные параметры

**Обязательные параметры:**
- **CODE** — уникальный код робота (a-z, 0-9, _, -, .)
- **HANDLER** — URL обработчика робота
- **NAME** — название робота (строка или объект с локализацией)

**Дополнительные параметры:**
- **DESCRIPTION** — описание робота
- **PROPERTIES** — входные параметры робота
- **RETURN_PROPERTIES** — возвращаемые значения робота
- **USE_SUBSCRIPTION** — ожидать ли ответ от приложения (Y/N)
- **AUTH_USER_ID** — ID пользователя для авторизации
- **FILTER** — ограничения по типу документа и редакции
- **DOCUMENT_TYPE** — тип документа для определения доступных полей

### 2.3. Пример 1: Простой робот без параметров

Робот, который отправляет уведомление:

```javascript
const $b24 = await B24Js.initializeB24Frame();

try {
    const response = await $b24.actions.v2.call.make({ method: 'bizproc.robot.add', params: {
            'CODE': 'send_notification',
            'HANDLER': 'https://your-app.com/robot-handler.php',
            'AUTH_USER_ID': 1,
            'USE_SUBSCRIPTION': 'N',
            'NAME': {
                'ru': 'Отправить уведомление',
                'en': 'Send notification'
            },
            'DESCRIPTION': {
                'ru': 'Отправляет уведомление во внешнюю систему',
                'en': 'Sends notification to external system'
            }
        } });
    
    console.log('Робот зарегистрирован:', response.getData().result);
} catch (error) {
    console.error('Ошибка:', error);
}
```

### 2.4. Пример 2: Робот с входными параметрами

Робот, который отправляет сообщение с настраиваемым текстом:

```javascript
const $b24 = await B24Js.initializeB24Frame();

await $b24.actions.v2.call.make({ method: 'bizproc.robot.add', params: {
        'CODE': 'send_custom_message',
        'HANDLER': 'https://your-app.com/send-message.php',
        'AUTH_USER_ID': 1,
        'USE_SUBSCRIPTION': 'N',
        'NAME': {
            'ru': 'Отправить сообщение',
            'en': 'Send message'
        },
        'PROPERTIES': {
            'message_text': {
                'Name': {
                    'ru': 'Текст сообщения',
                    'en': 'Message text'
                },
                'Type': 'text',
                'Required': 'Y',
                'Default': 'Здравствуйте!'
            },
            'recipient': {
                'Name': {
                    'ru': 'Получатель',
                    'en': 'Recipient'
                },
                'Type': 'user',
                'Required': 'Y'
            },
            'send_time': {
                'Name': {
                    'ru': 'Время отправки',
                    'en': 'Send time'
                },
                'Type': 'datetime',
                'Required': 'N'
            }
        },
        'FILTER': {
            'INCLUDE': [
                ['crm', 'CCrmDocumentDeal'],
                ['crm', 'CCrmDocumentLead']
            ]
        }
    } });
```

### 2.5. Типы параметров (PROPERTIES)

Доступные типы параметров:

| Тип | Описание |
|-----|----------|
| `string` | Строка |
| `text` | Многострочный текст |
| `int` | Целое число |
| `double` | Число с десятичной дробью |
| `bool` | Да/Нет |
| `date` | Дата |
| `datetime` | Дата и время |
| `user` | Пользователь |
| `select` | Список (требует Options) |

### 2.6. Пример 3: Робот с параметром типа "Список"

```javascript
const $b24 = await B24Js.initializeB24Frame();

await $b24.actions.v2.call.make({ method: 'bizproc.robot.add', params: {
        'CODE': 'create_document',
        'HANDLER': 'https://your-app.com/create-doc.php',
        'AUTH_USER_ID': 1,
        'USE_SUBSCRIPTION': 'Y', // Ожидаем ответ
        'NAME': {
            'ru': 'Создать документ',
            'en': 'Create document'
        },
        'PROPERTIES': {
            'doc_type': {
                'Name': {
                    'ru': 'Тип документа',
                    'en': 'Document type'
                },
                'Type': 'select',
                'Required': 'Y',
                'Default': 'pdf',
                'Options': {
                    'pdf': 'PDF',
                    'docx': 'DOCX',
                    'xlsx': 'XLSX'
                }
            },
            'doc_title': {
                'Name': {
                    'ru': 'Название документа',
                    'en': 'Document title'
                },
                'Type': 'string',
                'Required': 'Y'
            },
            'include_attachments': {
                'Name': {
                    'ru': 'Включить вложения',
                    'en': 'Include attachments'
                },
                'Type': 'bool',
                'Default': 'N'
            }
        },
        'RETURN_PROPERTIES': {
            'document_url': {
                'Name': {
                    'ru': 'Ссылка на документ',
                    'en': 'Document URL'
                },
                'Type': 'string'
            },
            'document_id': {
                'Name': {
                    'ru': 'ID документа',
                    'en': 'Document ID'
                },
                'Type': 'string'
            }
        }
    } });
```

## Шаг 3: Обработчик робота

### 3.1. Создайте обработчик

Когда робот запускается в бизнес-процессе, Bitrix24 отправляет POST-запрос на URL обработчика.

Файл `robot-handler.php`:

```php
<?php
// Получаем данные от Bitrix24
$data = json_decode(file_get_contents('php://input'), true);

// Логируем для отладки
file_put_contents('robot-log.txt', print_r($data, true), FILE_APPEND);

// Получаем параметры робота
$properties = $data['properties'] ?? [];
$documentId = $data['document_id'] ?? [];
$eventToken = $data['event_token'] ?? '';

// Пример: получаем текст сообщения, который настроил пользователь
$messageText = $properties['message_text'] ?? 'Сообщение по умолчанию';
$recipient = $properties['recipient'] ?? null;

// Выполняем бизнес-логику
// Например, отправляем запрос во внешний API
$result = sendToExternalAPI($messageText, $recipient);

// Если робот использует USE_SUBSCRIPTION = 'Y',
// нужно отправить результат обратно в Bitrix24
if ($eventToken && $result['success']) {
    sendResultToBitrix24($eventToken, [
        'document_url' => $result['url'],
        'document_id' => $result['id']
    ]);
}

function sendToExternalAPI($message, $recipient) {
    // Ваша логика отправки
    return [
        'success' => true,
        'url' => 'https://example.com/document/123',
        'id' => '123'
    ];
}

function sendResultToBitrix24($eventToken, $returnValues) {
    // Отправляем результат через bizproc.event.send
    $b24Webhook = 'https://your-portal.bitrix24.com/rest/1/your_webhook/';
    
    $data = [
        'event_token' => $eventToken,
        'return_values' => $returnValues,
        'log_message' => 'Документ успешно создан'
    ];
    
    $ch = curl_init($b24Webhook . 'bizproc.event.send');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}
?>
```

### 3.2. Что получает обработчик

Обработчик робота получает POST-запрос с JSON-данными:

```json
{
    "event_token": "55|88|0|133|10|570fd76e352f333fc606805faaa3a563",
    "document_id": ["crm", "CCrmDocumentDeal", "DEAL_142"],
    "document_type": ["crm", "CCrmDocumentDeal", "DEAL"],
    "properties": {
        "message_text": "Текст, введенный пользователем",
        "recipient": "user_1",
        "send_time": "2025-10-27 15:30:00"
    },
    "auth": {
        "access_token": "...",
        "refresh_token": "...",
        "expires_in": 3600,
        "domain": "portal.bitrix24.com",
        "member_id": "..."
    }
}
```

**Основные поля:**
- **event_token** — токен для отправки результата (если USE_SUBSCRIPTION = 'Y')
- **document_id** — идентификатор документа (сделки, лида и т.д.)
- **document_type** — тип документа
- **properties** — значения параметров, настроенных пользователем
- **auth** — данные авторизации для вызова REST API

## Шаг 4: Возврат результатов робота

### 4.1. Метод bizproc.event.send

Если робот должен вернуть данные (USE_SUBSCRIPTION = 'Y'), используйте метод `bizproc.event.send`.

Документация: https://github.com/bitrix24/b24restdocs/blob/main/api-reference/bizproc/bizproc-robot/bizproc-event-send.md

### 4.2. Пример возврата результата

```php
<?php
// После выполнения логики робота
$eventToken = $data['event_token'];

// Результаты для возврата
$returnValues = [
    'document_url' => 'https://docs.example.com/doc123.pdf',
    'document_id' => 'doc123'
];

// Отправляем результат в Bitrix24
$result = $b24->call('bizproc.event.send', [
    'event_token' => $eventToken,
    'return_values' => $returnValues,
    'log_message' => 'Документ создан успешно'
]);
```

### 4.3. Использование возвращаемых значений

После выполнения робота возвращаемые значения становятся доступны в следующих роботах бизнес-процесса:

```
Робот "Создать документ" вернул:
  - {=document_url}
  - {=document_id}

Следующий робот может использовать:
  "Отправить ссылку: {=document_url}"
```

## Шаг 5: Фильтрация по типам документов

### 5.1. Параметр FILTER

Ограничьте доступность робота для определенных типов документов:

```javascript
'FILTER': {
    'INCLUDE': [
        ['crm', 'CCrmDocumentDeal'],  // Только для сделок
        ['crm', 'CCrmDocumentLead']   // Только для лидов
    ]
}
```

Или исключите определенные типы:

```javascript
'FILTER': {
    'EXCLUDE': [
        'box'  // Не показывать в коробочной версии
    ]
}
```

### 5.2. Типы документов

Доступные типы документов CRM:

| Тип документа | Описание |
|---------------|----------|
| `['crm', 'CCrmDocumentLead', 'LEAD']` | Лиды |
| `['crm', 'CCrmDocumentDeal', 'DEAL']` | Сделки |
| `['crm', 'CCrmDocumentContact', 'CONTACT']` | Контакты |
| `['crm', 'CCrmDocumentCompany', 'COMPANY']` | Компании |
| `['crm', 'Bitrix\Crm\Integration\BizProc\Document\Quote', 'QUOTE']` | Коммерческие предложения |
| `['crm', 'Bitrix\Crm\Integration\BizProc\Document\SmartInvoice', 'SMART_INVOICE']` | Счета |
| `['crm', 'Bitrix\Crm\Integration\BizProc\Document\Dynamic', 'DYNAMIC_XXX']` | Смарт-процессы (XXX - ID) |

## Шаг 6: Дополнительные настройки робота

### 6.1. Использование встройки для настроек (USE_PLACEMENT)

Вы можете добавить кнопку "Настроить" для открытия дополнительного интерфейса:

```javascript
const $b24 = await B24Js.initializeB24Frame();

await $b24.actions.v2.call.make({ method: 'bizproc.robot.add', params: {
        'CODE': 'advanced_robot',
        'HANDLER': 'https://your-app.com/robot.php',
        'USE_SUBSCRIPTION': 'Y',
        'USE_PLACEMENT': 'Y',
        'PLACEMENT_HANDLER': 'https://your-app.com/robot-settings.php',
        'NAME': {
            'ru': 'Робот с настройками',
            'en': 'Robot with settings'
        },
        'PROPERTIES': {
            'config': {
                'Name': 'Конфигурация',
                'Type': 'string'
            }
        }
    } });
```

При клике на "Настроить" откроется слайдер с вашим интерфейсом настройки.

### 6.2. Параметр DOCUMENT_TYPE

Указывает тип документа для правильного отображения доступных полей в интерфейсе настройки:

```javascript
'DOCUMENT_TYPE': ['crm', 'CCrmDocumentDeal', 'DEAL']
```

Это позволяет пользователю выбирать поля сделки при настройке робота.

## Шаг 7: Управление роботами

### 7.1. Получить список роботов

```javascript
const $b24 = await B24Js.initializeB24Frame();

const response = await $b24.actions.v2.call.make({ method: 'bizproc.robot.list' });
console.log('Список роботов:', response.getData().result);
```

Документация: https://github.com/bitrix24/b24restdocs/blob/main/api-reference/bizproc/bizproc-robot/bizproc-robot-list.md

### 7.2. Обновить робота

```javascript
const $b24 = await B24Js.initializeB24Frame();

await $b24.actions.v2.call.make({ method: 'bizproc.robot.update', params: {
        'CODE': 'send_notification',
        'NAME': {
            'ru': 'Новое название',
            'en': 'New name'
        }
    } });
console.log('Робот обновлен');
```

Документация: https://github.com/bitrix24/b24restdocs/blob/main/api-reference/bizproc/bizproc-robot/bizproc-robot-update.md

### 7.3. Удалить робота

```javascript
const $b24 = await B24Js.initializeB24Frame();

await $b24.actions.v2.call.make({ method: 'bizproc.robot.delete', params: {
        'CODE': 'send_notification'
    } });
console.log('Робот удален');
```

Документация: https://github.com/bitrix24/b24restdocs/blob/main/api-reference/bizproc/bizproc-robot/bizproc-robot-delete.md

## Примеры использования

### Пример 1: Робот интеграции с внешним API

**Задача:** Получить курс валюты из внешнего API

```javascript
const $b24 = await B24Js.initializeB24Frame();

// Регистрация робота
await $b24.actions.v2.call.make({ method: 'bizproc.robot.add', params: {
        'CODE': 'get_currency_rate',
        'HANDLER': 'https://your-app.com/currency.php',
        'USE_SUBSCRIPTION': 'Y',
        'NAME': {
            'ru': 'Получить курс валюты',
            'en': 'Get currency rate'
        },
        'PROPERTIES': {
            'currency_code': {
                'Name': {
                    'ru': 'Код валюты',
                    'en': 'Currency code'
                },
                'Type': 'select',
                'Options': {
                    'USD': 'Доллар США',
                    'EUR': 'Евро',
                    'GBP': 'Фунт стерлингов'
                },
                'Required': 'Y',
                'Default': 'USD'
            }
        },
        'RETURN_PROPERTIES': {
            'rate': {
                'Name': {
                    'ru': 'Курс',
                    'en': 'Rate'
                },
                'Type': 'double'
            },
            'date': {
                'Name': {
                    'ru': 'Дата курса',
                    'en': 'Rate date'
                },
                'Type': 'date'
            }
        }
    } });
```

Обработчик `currency.php`:

```php
<?php
$data = json_decode(file_get_contents('php://input'), true);
$currencyCode = $data['properties']['currency_code'] ?? 'USD';
$eventToken = $data['event_token'];

// Запрос к внешнему API
$apiUrl = "https://api.exchangerate-api.com/v4/latest/{$currencyCode}";
$response = file_get_contents($apiUrl);
$rates = json_decode($response, true);

// Возвращаем результат
$b24->call('bizproc.event.send', [
    'event_token' => $eventToken,
    'return_values' => [
        'rate' => $rates['rates']['RUB'],
        'date' => date('Y-m-d')
    ]
]);
?>
```

### Пример 2: Робот отправки данных в CRM

**Задача:** Создать контакт в другой CRM-системе

```javascript
const $b24 = await B24Js.initializeB24Frame();

await $b24.actions.v2.call.make({ method: 'bizproc.robot.add', params: {
        'CODE': 'export_to_external_crm',
        'HANDLER': 'https://your-app.com/export-contact.php',
        'USE_SUBSCRIPTION': 'Y',
        'NAME': {
            'ru': 'Экспорт в внешнюю CRM',
            'en': 'Export to external CRM'
        },
        'PROPERTIES': {
            'contact_name': {
                'Name': 'Имя контакта',
                'Type': 'string',
                'Required': 'Y'
            },
            'contact_email': {
                'Name': 'Email',
                'Type': 'string',
                'Required': 'Y'
            },
            'contact_phone': {
                'Name': 'Телефон',
                'Type': 'string'
            }
        },
        'RETURN_PROPERTIES': {
            'external_id': {
                'Name': 'ID во внешней системе',
                'Type': 'string'
            },
            'success': {
                'Name': 'Успешно',
                'Type': 'bool'
            }
        },
        'FILTER': {
            'INCLUDE': [
                ['crm', 'CCrmDocumentContact'],
                ['crm', 'CCrmDocumentLead']
            ]
        }
    } });
```

## Полезные ссылки

### Основная документация
- Бизнес-процессы и роботы: https://github.com/bitrix24/b24restdocs/blob/main/api-reference/bizproc/index.md
- Регистрация робота: https://github.com/bitrix24/b24restdocs/blob/main/api-reference/bizproc/bizproc-robot/bizproc-robot-add.md
- Обновление робота: https://github.com/bitrix24/b24restdocs/blob/main/api-reference/bizproc/bizproc-robot/bizproc-robot-update.md
- Удаление робота: https://github.com/bitrix24/b24restdocs/blob/main/api-reference/bizproc/bizproc-robot/bizproc-robot-delete.md
- Список роботов: https://github.com/bitrix24/b24restdocs/blob/main/api-reference/bizproc/bizproc-robot/bizproc-robot-list.md
- Отправка результата: https://github.com/bitrix24/b24restdocs/blob/main/api-reference/bizproc/bizproc-robot/bizproc-event-send.md

### Действия бизнес-процессов
- Регистрация действия: https://github.com/bitrix24/b24restdocs/blob/main/api-reference/bizproc/bizproc-activity/bizproc-activity-add.md
- Обзор действий: https://github.com/bitrix24/b24restdocs/blob/main/api-reference/bizproc/bizproc-activity/index.md

### Туториалы
- Туториалы по бизнес-процессам: https://github.com/bitrix24/b24restdocs/blob/main/tutorials/bizproc/index.md
- Учебный курс по бизнес-процессам: https://github.com/bitrix24/b24restdocs/blob/main/api-reference/bizproc/bizproc-tutorials.md

### Работа с шаблонами
- Добавление шаблона бизнес-процесса: https://github.com/bitrix24/b24restdocs/blob/main/api-reference/bizproc/template/bizproc-workflow-template-add.md
- Запуск бизнес-процесса: https://github.com/bitrix24/b24restdocs/blob/main/api-reference/bizproc/bizproc-workflow-start.md

### CRM
- Типы объектов CRM: https://github.com/bitrix24/b24restdocs/blob/main/api-reference/crm/data-types.md
- Универсальные методы CRM: https://github.com/bitrix24/b24restdocs/blob/main/api-reference/crm/universal/index.md
- Смарт-процессы: https://github.com/bitrix24/b24restdocs/blob/main/api-reference/crm/universal/user-defined-object-types/index.md

## Типичные ошибки и их решение

1. **Ошибка "Application context required"** → Роботы работают только в контексте приложения
2. **Робот не отображается в интерфейсе** → Проверьте параметр FILTER и тип документа
3. **Не приходят данные на обработчик** → Убедитесь, что URL доступен извне, проверьте логи
4. **Результаты не возвращаются** → Убедитесь, что USE_SUBSCRIPTION = 'Y' и вызываете bizproc.event.send
5. **Ошибка валидации параметров** → Проверьте Required и Type для каждого параметра

## Заключение

Создание робота состоит из:
1. Создания приложения с правами **bizproc**
2. Регистрации робота через `bizproc.robot.add` с определением входных и выходных параметров
3. Создания обработчика, который принимает данные и выполняет бизнес-логику
4. Возврата результатов через `bizproc.event.send` (опционально)

Ваш робот станет доступен пользователям в интерфейсе настройки автоматизации CRM и в дизайнере бизнес-процессов.

