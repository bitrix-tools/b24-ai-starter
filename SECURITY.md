# Политика безопасности

<sub>Last reviewed: 2026-10-07.</sub>

## Как сообщить об уязвимости

**Используйте приватный канал GitHub:**
[Report a vulnerability](https://github.com/bitrix-tools/b24-ai-starter/security/advisories/new).

Отчёт виден только мейнтейнерам и не попадает в публичный доступ, пока готовится исправление.

**Не открывайте публичный issue для проблем безопасности.** Стартер копируют в реальные приложения: пока исправление не вышло, каждое такое приложение уязвимо.

Если приватный канал недоступен, откройте обычный issue с текстом *«security report, please advise a private channel»* — без деталей, воспроизведения и версий.

## Что помогает

- Какой бэкенд (PHP / Python / Node) и какая версия стартера (коммит).
- Что конкретно получает атакующий: «OAuth-токен портала попадает в лог» — полезно; «логирование небезопасно» — нет.
- Воспроизведение, пусть грубое.

## Что входит в зону ответственности

- Код этого репозитория: `frontend/`, `backends/*`, `infrastructure/`, `docker-compose.yml`, скрипты.
- Проверка JWT, обработка OAuth-токенов Bitrix24, обработчики событий `/api/app-events/`.

Уязвимости в SDK сообщайте в их репозитории:
[b24jssdk](https://github.com/bitrix24/b24jssdk/security/advisories/new),
[b24phpsdk](https://github.com/bitrix24/b24phpsdk),
[b24pysdk](https://github.com/bitrix24/b24pysdk).
