# План живой проверки

<sub>Last reviewed: 2026-10-09.</sub>

Пошаговый план ручной проверки стартера на реальном портале Bitrix24 после #25, #23, #30, #33 (надёжная установка, хост туннеля) и #34 (новый каркас фронтенда). Что уже проверено автоматически — уровень 0 в [testing.md](testing.md); здесь только то, что CI проверить не может.

## Цель

Подтвердить на живом портале:

1. Установка, переустановка (#8) и удаление приложения работают на всех трёх бэкендах и обеих СУБД.
2. Фронтенд после перехода на JS SDK 3 / b24ui 2.14 и на каркас `B24Dashboard*` (боковое меню, панели) работает во всех местах встройки.
3. `/api/getToken` выдаёт JWT настоящему пользователю портала и **не** выдаёт кому попало (проверка `AUTH_ID` OAuth-сервером Bitrix24).
4. События жизненного цикла доходят и меняют состояние аккаунта в БД.
5. Production-сборка (`make prod-*`) открывается в портале.

## Подготовка (один раз)

| Что | Как |
| --- | --- |
| Тестовый портал | Отдельный портал (не боевой), права администратора. Второй пользователь **без** прав администратора — для проверок B1.4 и C3 |
| Локальное приложение | Портал → Разработчикам → Другое → Локальное приложение (серверное, с интерфейсом). Права: `crm, user_brief, pull, placement, userfieldconfig`. URL обработчика и установки — из `make dev-init` (домен Cloudpub) |
| `.env` | `make dev-init`: `CLIENT_ID`, `CLIENT_SECRET`, `CLOUDPUB_TOKEN`, `VIRTUAL_HOST`, `DB_TYPE`, `ENABLE_RABBITMQ` |
| CRM | Хотя бы одна сделка — для вкладки сделки (B5) |
| Браузер | DevTools открыты: вкладки Console и Network (фильтр `api/`) |
| Доступ к OAuth-серверам | Из контейнера бэкенда: `docker exec api sh -c "wget -qO- https://oauth.bitrix.info/ >/dev/null && echo ok"` (или `curl`). Без доступа `/api/getToken` вернёт `503` — это ожидаемое поведение, но тогда проверка невозможна |

**Команды для проверки БД** (подставьте свои `DB_USER`/`DB_NAME` из `.env`):

```bash
# PostgreSQL — таблица PHP и Node
docker compose exec database-postgres psql -U appuser -d appdb -c \
  "SELECT member_id, domain_url, b24_user_id, status, application_token IS NOT NULL AS has_app_token, updated_at_utc
     FROM bitrix24account ORDER BY updated_at_utc DESC LIMIT 5;"

# MySQL — то же
docker compose exec database-mysql sh -lc 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" -e \
  "SELECT member_id, domain_url, b24_user_id, status, application_token IS NOT NULL AS has_app_token, updated_at_utc
     FROM bitrix24account ORDER BY updated_at_utc DESC LIMIT 5;"'

# Python хранит аккаунты в своей таблице: bitrix_auth_bitrix24account (те же поля domain_url, status)
```

## Матрица прогонов

Прогоны идут по убыванию риска. Каждый прогон начинается с чистой БД: `make down` → `make clean` (⚠️ удаляет тома с данными) → `make dev-<backend>`. Исключение — R2: там проверяется миграция **существующей** базы.

| Прогон | Бэкенд | БД | Очередь | Блоки | Почему так | ~Время |
| --- | --- | --- | --- | --- | --- | --- |
| R1 | PHP | PostgreSQL | выкл | A, B (все), C | Больше всего изменений: фикс запуска, `getToken`, логи; каркас фронтенда (B13, B14) | 60 мин |
| R2 | PHP | MySQL | выкл | A, B1–B3, B9, D | Переустановка и `make db-upgrade` на MySQL | 30 мин |
| R3 | Python | PostgreSQL | **выкл** | A, B1–B3, B7, B9, B10a, C1–C3 | События без RabbitMQ обрабатываются сразу (раньше терялись) | 40 мин |
| R4 | Python | MySQL | **вкл** | A, B1, B7, B10b | События через Celery / `python-worker` | 25 мин |
| R5 | Node | PostgreSQL | выкл | A, B (все), C | Установка и события в Node — новый код | 60 мин |
| R6 | Node | MySQL | выкл | A, B1–B3, B7, B9 | Тот же код на MySQL | 25 мин |
| R7 | один (рекомендуется PHP) | PostgreSQL | выкл | E | Production-сборка | 30 мин |

Минимальный набор, если времени мало: **R1 + R5 + R3**.

## Блок A — smoke без портала (каждый прогон, ~5 мин)

| ID | Шаг | Ожидание |
| --- | --- | --- |
| A1 | `make dev-<backend>`, затем `docker ps` | Контейнеры `frontend`, `api`, БД (и `rabbitmq`/`python-worker`, если очередь включена) — `Up`, без рестартов |
| A2 | `make logs` | Нет ошибок старта. PHP: нет `Unrecognized options`, `Environment variable not found`, `must therefore be declared abstract` (ошибки, исправленные в #30). Python: нет `makemigrations` |
| A3 | `curl -s localhost:8000/api/health` | `200`, `{"status":"healthy","backend":"<backend>",...}` без токена |
| A4 | `curl -s -o /dev/null -w '%{http_code}' localhost:8000/api/enum` | `401` |
| A5 | `curl -s -X POST localhost:8000/api/getToken -H 'Content-Type: application/json' -d '{"DOMAIN":"example.bitrix24.ru","member_id":"x","AUTH_ID":"forged"}'` | `401` (не `200` с токеном) |
| A6 | Только PHP: `make test-php` | Все тесты проходят |
| A7 | Открыть `VIRTUAL_HOST` (адрес Cloudpub) в обычной вкладке браузера | Nuxt отвечает (страница ошибки «не во фрейме» — нормально), **нет** `Blocked request. This host is not allowed` (#33: хост берётся из `VIRTUAL_HOST`) |

## Блок B — сценарии на портале

| ID | Сценарий | Шаги | Ожидание | Где смотреть |
| --- | --- | --- | --- | --- |
| B1 | Установка | Открыть приложение впервые (под администратором) | Страница `/install` — на весь экран, **без** бокового меню (layout `clear`); прогресс проходит все шаги (placement, userfield, server side), конфетти, затем главная с боковым меню | Network: `POST /api/install` → `200 {"message":"Installation successful"}` |
| B1.1 | Аккаунт в БД | Запрос из «Подготовки» | PHP/Node: строка со статусом `new` или `active`. Python: строка в `bitrix_auth_bitrix24account` | БД |
| B1.2 | События привязаны | Портал → приложение → «Обработчики событий» (или REST `event.get`) | `ONAPPINSTALL`, `ONAPPUNINSTALL` → `<VIRTUAL_HOST>/api/app-events/` | Портал |
| B1.3 | Логи без токенов | `make logs` после установки | В логах нет значений `AUTH_ID`, `access_token`, `refresh_token`, `application_token` — только `***` | Логи бэкенда |
| B1.5 | Ошибка установки (#33) | Новый портал или после удаления: `docker stop api`, открыть приложение; затем `docker start api` и открыть снова | Прогресс краснеет, toast «Ошибка установки» с причиной, страница **остаётся** (не страница ошибки); после старта бэкенда установка проходит | UI, Console: `Install failed` с шагом |
| B1.6 | Нет `VIRTUAL_HOST` (по желанию) | `NUXT_PUBLIC_APP_URL` пустой (`VIRTUAL_HOST=` в `.env`, перезапуск `frontend`), открыть установку | Toast «NUXT_PUBLIC_APP_URL (VIRTUAL_HOST) is not set», placement **не** регистрируется с относительным URL. Вернуть значение | UI |
| B1.4 | Не-администратор | Установка под пользователем без прав админа (если портал позволяет) | Python: `403`. Остальные — Bitrix24 сам не даёт установить | Network |
| B2 | Переустановка (#8) | Удалить приложение в портале → установить снова | Установка проходит, нет `500` / `SQLSTATE[23505]` / `Duplicate entry` | Network, логи |
| B2.2 | Встройки без дублей | После B2: карточка сделки, список типов полей | Одна вкладка `[demo] Some Tab` (старая снимается `placement.unbind`), один тип `[dev] Some Type` (обновлён, не добавлен второй) | UI, REST `placement.get` |
| B2.1 | Состояние БД | Запрос из «Подготовки» | Старая строка `deleted` (Node) — и новая `new`/`active`. В PHP старая строка может остаться не `deleted` — известный пробел, не ошибка | БД |
| B3 | Главная и JWT | Открыть приложение: в шапке заголовок, в панели инструментов кнопки `getEnums` / `getItems`, ниже «Статус бэкенда». Нажать обе кнопки | Пока идёт инициализация — скелетоны; статус бэкенда зелёный; данные приходят (Console); в Network `POST /api/getToken` → `200` с `token`, запросы `enum`/`list` с `Authorization: Bearer` | Network |
| B3.1 | Язык | Сменить язык портала (например, на Deutsch), перезагрузить | Интерфейс на выбранном языке | UI |
| B4 | Слайдер настроек | Кнопка «Настройки» в шапке главной (только админ) или меню приложения в портале → `slider/app-options`; изменить значения, сохранить | В слайдере шапка с заголовком и описанием, кнопки «Сохранить»/«Отмена» в нижней панели, бокового меню нет. Слайдер закрывается; при повторном открытии значения сохранены; в соседней вкладке приложения настройки обновились (pull `reload.options`) | UI, Console |
| B5 | Вкладка сделки | CRM → сделка → вкладка приложения | Вкладка открывается **без** бокового меню (layout `placement` не менялся), компоненты B24 отрисованы, индикатор загрузки появляется и исчезает | UI, Console без ошибок |
| B6 | Пользовательское поле | Добавить поле типа приложения в сделку, изменить значение | Значение отображается и сохраняется; после перезагрузки — то же значение | UI |
| B7 | События жизненного цикла | После B1 подождать ~10 с, проверить БД | PHP/Node: статус `active`, `has_app_token = true`. Python: аккаунт/установка активны | БД, логи `/api/app-events/` |
| B8 | Логи фронтенда | Console в dev-режиме | Логи `LoggerFactory` видны; нет `LoggerBrowser`, нет токенов в выводе | Console |
| B9 | Удаление | Удалить приложение в портале | Node: статус `deleted`. Python: аккаунт неактивен. PHP: событие в логе, статус не меняется (известный пробел) | БД, логи |
| B9.1 | JWT после удаления | Повторить A5, но с реальными `DOMAIN`/`member_id` удалённого приложения (из Network до удаления) | `401` | curl |
| B10a | Python без очереди | `ENABLE_RABBITMQ=0`: установка / удаление | Ответ `/api/app-events/` — `{"status":"processed"}`, состояние в БД меняется сразу | Логи `api` |
| B10b | Python с очередью | `ENABLE_RABBITMQ=1`: то же | Ответ `{"status":"queued"}`; `docker logs python-worker` — задача выполнена; состояние в БД меняется | Логи `python-worker` |
| B11 | Страница ошибки | На `handler/uf.demo` остановить бэкенд (`docker stop api`) и обновить | Страница ошибки с кнопкой «очистить»; кнопка ведёт обратно на `/handler/uf.demo`. Затем `docker start api` | UI |
| B12 | Локали | Язык портала → Tiếng Việt, затем 日本語 | Нет английских «дыр» на главной, во вкладке сделки, в поле | UI |
| B13 | Каркас приложения (#34) | Главная: свернуть/развернуть боковое меню и потянуть его край; перезагрузить; сузить окно < 1024 px; Ctrl/⌘+K; внешние ссылки меню; вход под не-админом | Меню сворачивается, ширина меняется, состояние сохраняется после перезагрузки; на узком экране меню открывается поверх (кнопка-гамбургер); поиск находит пункты меню; ссылки открываются в новой вкладке; внизу меню — имя пользователя; у не-админа нет кнопки «Настройки» | UI |
| B14 | Телеметрия в меню (только PHP) | `NUXT_PUBLIC_TELEMETRY_ENABLED=true`, перезапуск `frontend` | В меню пункт «Telemetry Test», страница открывается в панели с меню; при `false` пункта нет | UI |

## Блок C — безопасность (R1, R3, R5)

| ID | Шаг | Ожидание |
| --- | --- | --- |
| C1 | Скопировать из Network тело `POST /api/getToken`, заменить `AUTH_ID` на `forged`, отправить curl'ом | `401` |
| C2 | То же, но `DOMAIN` и `member_id` — от **другого** портала (или выдуманные), `AUTH_ID` — настоящий | `401` |
| C3 | Взять JWT из B3 и отправить `POST /api/install` с `Authorization: Bearer <jwt>` (Python) | `400` |
| C4 | `docker exec api sh -c "…"` с заблокированным доступом к `oauth.bitrix.info` и `oauth.bitrix24.tech` (например, временно неверный DNS) — по желанию | `/api/getToken` → `503`, в логе причина |
| C5 | `make security-tests` | Все проверки зелёные |

## Блок D — миграция существующей БД (R2)

| ID | Шаг | Ожидание |
| --- | --- | --- |
| D1 | `git checkout c9851f4` (до #23), `make clean`, `make dev-php` с `DB_TYPE=mysql`, установить приложение | Аккаунт создан |
| D2 | Удалить приложение, установить снова | **Падает** с `Duplicate entry` — воспроизведение старой ошибки |
| D3 | `git checkout master`, `make dev-php` **без** `make clean`, затем `make db-upgrade` | `✓ Database upgraded`; повторный `make db-upgrade` тоже проходит |
| D4 | Переустановить приложение | Проходит без ошибок, данные других аккаунтов на месте |

## Блок E — production (R7)

| ID | Шаг | Ожидание |
| --- | --- | --- |
| E1 | `make down`, затем `make prod-php` (или другой бэкенд) | Контейнеры подняты в фоне, `docker compose -f docker-compose.yml -f docker-compose.prod.yml config` — у `frontend` нет монтирования исходников |
| E2 | Открыть приложение в портале | Сценарии B1 и B3 проходят |
| E3 | Python (если выбран): `docker exec api python -c "import django,os;os.environ['DJANGO_SETTINGS_MODULE']='settings';django.setup();from django.conf import settings;print(settings.DEBUG)"` | `False` |
| E4 | `make down-all` | Все контейнеры остановлены |

## Критерии готовности

- R1, R3, R5 — все обязательные шаги пройдены; блок C пройден на всех трёх.
- R2 / D — переустановка на старой MySQL-базе после `make db-upgrade` работает.
- Нет новых ошибок в консоли браузера и логах бэкенда, нет токенов в логах.
- Известные пробелы (ниже) ошибками **не** считаются.

## Известные пробелы — не считать ошибками

- PHP: `ONAPPUNINSTALL` только логируется, аккаунт не помечается удалённым (testing.md).
- Смена домена портала не обрабатывается (#17).
- Эндпоинт телеметрии есть только в PHP — с Python/Node держите `NUXT_PUBLIC_TELEMETRY_ENABLED=false` (пункт меню «Telemetry Test» не появится).
- Тексты внешних ссылок меню (`Bitrix24 REST API`, `Bitrix24 UI Kit`, `GitHub`) не переводятся — это названия.

## Как сообщать о результатах

Одна строка на шаг в таблице результатов (можно скопировать в issue):

```text
| Прогон | ID | Результат (✅/❌/⏭) | Комментарий / ссылка на лог или скриншот |
```

Для каждого ❌ — отдельный issue по шаблону:

```text
Прогон / ID: R1 / B2
Бэкенд, БД, очередь: PHP, PostgreSQL, выкл
Шаги: …
Ожидалось: …
Получено: … (статус ответа, текст ошибки)
Логи: `make logs` — фрагмент вокруг ошибки (без токенов)
Скриншот / HAR: …
```

Проблемы безопасности (например, `/api/getToken` вернул `200` в C1/C2) — **не** в публичный issue, а по [SECURITY.md](../../SECURITY.md).
