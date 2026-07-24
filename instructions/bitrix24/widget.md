# Инструкция для ИИ: Создание приложения со встройкой в Bitrix24

## Описание
Эта инструкция описывает, как создать REST-приложение с виджетами (встройками) для Bitrix24. Виджеты позволяют встраивать функционал приложения в различные места интерфейса Bitrix24.

## Основные понятия

### Виджеты (встройки)
Виджеты — это элементы интерфейса вашего приложения, которые встраиваются в различные места Bitrix24:
- В карточки объектов CRM (сделки, лиды, контакты)
- В списки задач
- В меню портала
- В профили пользователей
- В мессенджер
- И другие места

### Типы приложений
1. **Локальное приложение** — создается администратором на конкретном портале Bitrix24
2. **Тиражное приложение** — публикуется в Bitrix24.Маркет для массового использования

Документация: https://github.com/bitrix24/b24restdocs/tree/main/local-integrations

## Шаг 1: Создание локального приложения

### 1.1. Создайте локальное приложение в Bitrix24

1. Зайдите в раздел **Разработчикам → Другое → Локальные приложения**
2. Нажмите **Добавить**
3. Укажите:
   - **Название приложения** (будет отображаться в меню)
   - **URL приложения** — основной адрес вашего обработчика
   - **Права доступа** (scope) — выберите необходимые разделы API

Документация: 
- https://github.com/bitrix24/b24restdocs/blob/main/local-integrations/local-apps.md
- https://github.com/bitrix24/b24restdocs/blob/main/local-integrations/serverside-local-app-with-ui.md

### 1.2. Базовый обработчик приложения

Создайте файл-обработчик на вашем сервере (например, `index.php`):

```php
<?php
// Получаем данные от Bitrix24
$data = $_POST;

// Параметры авторизации
$domain = $data['DOMAIN'];
$authToken = $data['AUTH_ID'];
$refreshToken = $data['REFRESH_ID'];

// Отображаем интерфейс приложения
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Мое приложение</title>
    <script src="https://unpkg.com/@bitrix24/b24jssdk@latest/dist/umd/index.min.js"></script>
</head>
<body>
    <h1>Добро пожаловать в приложение!</h1>
    <div id="app"></div>
    
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            try {
                // Инициализируем B24 Frame
                const $b24 = await B24Js.initializeB24Frame();
                console.log('Приложение инициализировано');
                
                // Получаем информацию о текущем пользователе
                const response = await $b24.actions.v2.call.make({ method: 'user.current' });
                console.log('Текущий пользователь:', response.getData().result);
            } catch (error) {
                console.error('Ошибка инициализации:', error);
            }
        });
    </script>
</body>
</html>
```

## Шаг 2: Регистрация виджета (встройки)

### 2.1. Метод placement.bind

Для регистрации виджета используется метод `placement.bind`. Регистрацию обычно выполняют при установке приложения.

Документация: https://github.com/bitrix24/b24restdocs/blob/main/api-reference/widgets/placement-bind.md

### 2.2. Параметры метода

- **PLACEMENT** — код места встройки (обязательный)
- **HANDLER** — URL обработчика виджета (обязательный)
- **TITLE** — название виджета в интерфейсе
- **DESCRIPTION** — описание виджета
- **LANG_ALL** — локализация названий для разных языков
- **OPTIONS** — дополнительные параметры (зависит от типа виджета)

### 2.3. Пример регистрации виджета

Вкладка в карточке сделки CRM:

```javascript
const $b24 = await B24Js.initializeB24Frame();

try {
    const response = await $b24.actions.v2.call.make({ method: 'placement.bind', params: {
            'PLACEMENT': 'CRM_DEAL_DETAIL_TAB',
            'HANDLER': 'https://your-domain.com/widget-handler.php',
            'TITLE': 'Моя вкладка',
            'DESCRIPTION': 'Дополнительная информация',
            'LANG_ALL': {
                'ru': {
                    'TITLE': 'Моя вкладка',
                    'DESCRIPTION': 'Дополнительная информация'
                },
                'en': {
                    'TITLE': 'My Tab',
                    'DESCRIPTION': 'Additional information'
                }
            }
        } });
    
    console.log('Виджет зарегистрирован успешно:', response.getData().result);
} catch (error) {
    console.error('Ошибка регистрации:', error);
}
```

Документация: https://github.com/bitrix24/b24restdocs/blob/main/api-reference/widgets/crm/detail-tab.md

## Шаг 3: Типы встроек (PLACEMENT)

### 3.1. Виджеты в CRM

Наиболее популярные места встройки в CRM:

| Код места встройки | Описание |
|-------------------|----------|
| `CRM_LEAD_DETAIL_TAB` | Вкладка в карточке лида |
| `CRM_DEAL_DETAIL_TAB` | Вкладка в карточке сделки |
| `CRM_CONTACT_DETAIL_TAB` | Вкладка в карточке контакта |
| `CRM_COMPANY_DETAIL_TAB` | Вкладка в карточке компании |
| `CRM_LEAD_LIST_MENU` | Пункт меню в списке лидов |
| `CRM_DEAL_LIST_MENU` | Пункт меню в списке сделок |
| `CRM_DEAL_DETAIL_TOOLBAR` | Кнопка в верхней панели карточки сделки |

Документация: https://github.com/bitrix24/b24restdocs/blob/main/api-reference/widgets/crm/index.md

**Пример 1: Виджет-вкладка в карточке сделки**

```javascript
const $b24 = await B24Js.initializeB24Frame();

await $b24.actions.v2.call.make({ method: 'placement.bind', params: {
        'PLACEMENT': 'CRM_DEAL_DETAIL_TAB',
        'HANDLER': 'https://your-app.com/deal-tab.php',
        'TITLE': 'Аналитика',
        'LANG_ALL': {
            'ru': { 'TITLE': 'Аналитика' },
            'en': { 'TITLE': 'Analytics' }
        }
    } });
```

При открытии этой вкладки ваш обработчик получит POST-запрос с параметрами:
```php
Array (
    [PLACEMENT] => CRM_DEAL_DETAIL_TAB
    [PLACEMENT_OPTIONS] => {"ID":"3473"}  // ID сделки
    [AUTH_ID] => токен_доступа
    [DOMAIN] => portal.bitrix24.com
)
```

**Пример 2: Пункт контекстного меню в списке сделок**

```javascript
const $b24 = await B24Js.initializeB24Frame();

await $b24.actions.v2.call.make({ method: 'placement.bind', params: {
        'PLACEMENT': 'CRM_DEAL_LIST_MENU',
        'HANDLER': 'https://your-app.com/deal-action.php',
        'TITLE': 'Экспорт в Excel'
    } });
```

### 3.2. Виджеты в задачах

| Код места встройки | Описание |
|-------------------|----------|
| `TASK_VIEW_TAB` | Вкладка в карточке задачи |
| `TASK_LIST_CONTEXT_MENU` | Пункт контекстного меню задачи |
| `TASK_USER_LIST_CONTEXT_MENU` | Пункт контекстного меню в списке задач пользователя |

Документация: https://github.com/bitrix24/b24restdocs/blob/main/api-reference/widgets/task/index.md

**Пример: Вкладка в карточке задачи**

```javascript
const $b24 = await B24Js.initializeB24Frame();

await $b24.actions.v2.call.make({ method: 'placement.bind', params: {
        'PLACEMENT': 'TASK_VIEW_TAB',
        'HANDLER': 'https://your-app.com/task-widget.php',
        'TITLE': 'Трекер времени'
    } });
```

### 3.3. Виджеты в других разделах

**Мессенджер:**
- `CHAT_MESSAGE` — кнопка в форме отправки сообщения
- `IM_SIDEBAR` — панель в боковой колонке чата

Документация: https://github.com/bitrix24/b24restdocs/blob/main/api-reference/widgets/im/index.md

**Рабочие группы:**
- `SONET_GROUP_DETAIL_TAB` — вкладка в карточке группы

Документация: https://github.com/bitrix24/b24restdocs/blob/main/api-reference/widgets/workgroups/index.md

**Универсальные виджеты:**
- `PAGE_BACKGROUND_WORKER` — невидимый виджет на каждой странице

Документация: https://github.com/bitrix24/b24restdocs/blob/main/api-reference/widgets/universal/background-worker.md

Полный список мест встройки: https://github.com/bitrix24/b24restdocs/blob/main/api-reference/widgets/placements.md

## Шаг 4: Обработчик виджета

### 4.1. Создайте обработчик виджета

Файл `widget-handler.php`:

```php
<?php
// Получаем данные от Bitrix24
$data = $_POST;

$placement = $data['PLACEMENT'];
$options = json_decode($data['PLACEMENT_OPTIONS'], true);
$entityId = $options['ID'] ?? null; // ID объекта (сделки, задачи и т.д.)

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <script src="https://unpkg.com/@bitrix24/b24jssdk@latest/dist/umd/index.min.js"></script>
</head>
<body>
    <h3>Мой виджет</h3>
    <div id="content">Загрузка...</div>
    
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            try {
                // Инициализируем B24 Frame
                const $b24 = await B24Js.initializeB24Frame();
                const entityId = <?= json_encode($entityId) ?>;
                
                // Получаем данные объекта через REST API
                const response = await $b24.actions.v2.call.make({ method: 'crm.deal.get', params: { id: entityId } });
                
                const deal = response.getData().result;
                document.getElementById('content').innerHTML = 
                    `Сделка: ${deal.TITLE}<br>Сумма: ${deal.OPPORTUNITY}`;
                
                // Автоматически подгоняем размер iframe
                $b24.fitWindow();
            } catch (error) {
                console.error('Ошибка:', error);
                document.getElementById('content').innerHTML = 'Ошибка загрузки данных';
            }
        });
    </script>
</body>
</html>
```

### 4.2. Что получает обработчик виджета

Обработчик получает POST-запрос с параметрами:
- **PLACEMENT** — код места встройки
- **PLACEMENT_OPTIONS** — JSON-строка с контекстными данными (ID объекта и т.д.)
- **AUTH_ID** — токен авторизации
- **REFRESH_ID** — токен обновления
- **DOMAIN** — домен портала
- **LANG** — язык интерфейса

## Шаг 5: Завершение установки приложения

После регистрации виджетов необходимо завершить установку приложения, чтобы виджеты стали доступны всем пользователям (не только администраторам).

```javascript
const $b24 = await B24Js.initializeB24Frame();

try {
    // Регистрируем обработчик события установки
    await $b24.actions.v2.call.make({ method: 'event.bind', params: {
            'event': 'ONAPPINSTALL',
            'handler': 'https://your-app.com/install.php'
        } });
    
    // Регистрируем виджеты
    await $b24.actions.v2.call.make({ method: 'placement.bind', params: {
        'PLACEMENT': 'CRM_DEAL_DETAIL_TAB',
        'HANDLER': 'https://your-app.com/widget.php',
        'TITLE': 'Мой виджет'
    } });
    
    // Завершаем установку
    await $b24.actions.v2.call.make({ method: 'app.info' });
    console.log('Установка завершена');
} catch (error) {
    console.error('Ошибка установки:', error);
}
```

Документация: https://github.com/bitrix24/b24restdocs/blob/main/settings/app-installation/installation-finish.md

## Шаг 6: Управление виджетами

### 6.1. Получить список зарегистрированных виджетов

```javascript
const $b24 = await B24Js.initializeB24Frame();

const response = await $b24.actions.v2.call.make({ method: 'placement.get' });
console.log('Зарегистрированные виджеты:', response.getData().result);
```

Документация: https://github.com/bitrix24/b24restdocs/blob/main/api-reference/widgets/placement-get.md

### 6.2. Удалить виджет

```javascript
const $b24 = await B24Js.initializeB24Frame();

await $b24.actions.v2.call.make({ method: 'placement.unbind', params: {
        'PLACEMENT': 'CRM_DEAL_DETAIL_TAB',
        'HANDLER': 'https://your-app.com/handler.php'
    } });
console.log('Виджет удален');
```

Документация: https://github.com/bitrix24/b24restdocs/blob/main/api-reference/widgets/placement-unbind.md

## Дополнительные возможности

### Интерактивное взаимодействие

Виджеты могут взаимодействовать с интерфейсом Bitrix24:

```javascript
const $b24 = await B24Js.initializeB24Frame();

// Открыть карточку сделки в слайдере
$b24.slider.openPath(
    $b24.slider.getUrl('/crm/deal/details/123/'),
    950
);

// Показать уведомление
await $b24.actions.v2.call.make({ method: 'im.notify', params: {
    to: await $b24.getAuth().userId,
    message: 'Данные сохранены!'
} });

// Закрыть текущий слайдер
$b24.slider.close();
```

Документация: https://github.com/bitrix24/b24restdocs/blob/main/api-reference/widgets/ui-interaction/index.md

### UI Kit для виджетов

Используйте готовые UI-компоненты Bitrix24 для создания единообразного интерфейса:

Документация: https://github.com/bitrix24/b24restdocs/blob/main/api-reference/widgets/ui-kit/index.md

## Полезные ссылки

### Основная документация
- Механизм встройки виджетов: https://github.com/bitrix24/b24restdocs/blob/main/api-reference/widgets/index.md
- Все места встройки: https://github.com/bitrix24/b24restdocs/blob/main/api-reference/widgets/placements.md
- Метод placement.bind: https://github.com/bitrix24/b24restdocs/blob/main/api-reference/widgets/placement-bind.md
- Локальные приложения: https://github.com/bitrix24/b24restdocs/blob/main/local-integrations/local-apps.md

### Примеры и туториалы
- Частые кейсы виджетов: https://github.com/bitrix24/b24restdocs/blob/main/api-reference/widgets/tutorials.md
- Виджет как вкладка в CRM: https://github.com/bitrix24/b24restdocs/blob/main/tutorials/crm/crm-widgets/widget-as-detail-tab.md
- Виджет как поле в карточке лида: https://github.com/bitrix24/b24restdocs/blob/main/tutorials/crm/crm-widgets/widget-as-field-in-lead-page.md

### Авторизация и безопасность
- Установка приложения: https://github.com/bitrix24/b24restdocs/blob/main/settings/app-installation/index.md
- OAuth 2.0: https://github.com/bitrix24/b24restdocs/blob/main/settings/oauth/index.md
- Как вызывать REST API: https://github.com/bitrix24/b24restdocs/blob/main/settings/how-to-call-rest-api/index.md

## Типичные ошибки и их решение

1. **Виджет не отображается** → Проверьте, завершена ли установка приложения (виджеты доступны только после завершения установки)
2. **Ошибка CORS** → Убедитесь, что домен обработчика совпадает с доменом, указанным в настройках приложения
3. **Не приходят данные в PLACEMENT_OPTIONS** → Проверьте, что обработчик получает POST-запрос
4. **Виджет регистрируется повторно** → Используйте `placement.unbind` перед повторной регистрацией
5. **Ошибка инициализации B24Js** → Убедитесь, что SDK подключен через CDN и страница загружается во фрейме Bitrix24

## Заключение

Создание приложения со встройкой состоит из:
1. Создания локального приложения в Bitrix24
2. Регистрации виджета через `placement.bind` с указанием кода места встройки (PLACEMENT)
3. Создания обработчика виджета, который получает данные и отображает интерфейс
4. Завершения установки приложения

Ваш виджет будет встроен в указанное место Bitrix24 и получит доступ к данным через REST API.

