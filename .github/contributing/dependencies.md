# Обновление зависимостей

<sub>Last reviewed: 2026-10-08.</sub>

## Автоматически

[Dependabot](../dependabot.yml) раз в неделю открывает PR: minor/patch — одной группой на экосистему, мажоры — по одному. Свежие релизы младше 3 дней пропускаются (`cooldown`). Docker-образы — раз в месяц.

## Вручную (мажорные версии)

1. Обнови пакет инструментом экосистемы, не правь lock-файлы руками:
   - фронтенд / Node: `pnpm add <pkg>@latest` (pnpm версии из `packageManager`);
   - Python: версия в `requirements.txt`, проверка `python manage.py check` и `python manage.py test`;
   - PHP: `composer update -W`. Плагин `symfony/flex` должен быть включён — он ограничивает `symfony/*` версией из `extra.symfony.require`. Без него (например, под root в неинтерактивной сессии) composer подтягивает компоненты Symfony 8.
2. Прочитай migration guide, исправь код, прогони проверки из [AGENTS.md](../../AGENTS.md#команды).
3. Обнови версии в `AGENTS.md`, `README.md`, навыках и `instructions/`, запись в `CHANGELOG.md` (`deps(<часть>): …`).

## Известные блокеры

| Пакет | Держим на | Причина | Когда снять |
| --- | --- | --- | --- |
| `typescript` | 6.0 | TS 7 не поддерживают `vue-tsc` и `typescript-eslint` | Когда оба объявят поддержку TS 7 |
| `symfony/*` | 7.4 LTS | `mesilov/bitrix24-php-lib` не поддерживает Symfony 8 | После релиза lib с Symfony 8 |
| `bitrix24/b24phpsdk` | коммит `dev-v3-dev#6ed9de3` | Совместим с `mesilov/bitrix24-php-lib` 0.5.2 | Когда lib поддержит новую версию SDK |

Node: Nuxt 4.6 требует Node ≥ 22.22.3; в Docker и CI — Node 24.
