# Telemetry Profiles Configuration Guide

## Обзор

Система профилей телеметрии позволяет контролировать какие атрибуты отправляются в OpenTelemetry Collector для различных типов приложений Bitrix24.

## Конфигурация

Конфигурация профилей находится в `config/packages/telemetry.yaml`.

### Активный профиль

Профиль задаётся через переменную окружения `OTEL_TELEMETRY_PROFILE` в `.env`:

```dotenv
OTEL_TELEMETRY_PROFILE=simple-ui
```

В `config/packages/telemetry.yaml` это выглядит так:

```yaml
parameters:
    telemetry.active_profile: '%env(string:OTEL_TELEMETRY_PROFILE)%'
```

Определяет какой профиль используется текущим приложением. Менять значение нужно только в `.env` — без пересборки кэша.

### Доступные профили

#### 1. simple-ui (по умолчанию)
**Назначение**: UI-centric приложения (b24-ai-starter-ru)  
**Профили**: LifecycleProfile + UIProfile  
**Атрибуты**: ~59 атрибутов
- Lifecycle события (установка, обновление, удаление)
- UI взаимодействия (кнопки, формы, экраны)

**Использование**:
```dotenv
OTEL_TELEMETRY_PROFILE=simple-ui
```

#### 2. integration-sync
**Назначение**: Интеграционные приложения с синхронизацией  
**Профили**: LifecycleProfile + UIProfile + IntegrationProfile  
**Исключения**: `initial_sync.*`  
**Атрибуты**: ~85 атрибутов

Подходит для приложений которые выполняют регулярную синхронизацию, но не имеют initial setup процесса.

**Использование**:
```dotenv
OTEL_TELEMETRY_PROFILE=integration-sync
```

#### 3. integration-with-migration
**Назначение**: Интеграции с initial setup процессом  
**Профили**: LifecycleProfile + UIProfile + IntegrationProfile  
**Атрибуты**: ~93 атрибута (полный IntegrationProfile)

Включает все integration атрибуты, включая initial_sync.

**Использование**:
```dotenv
OTEL_TELEMETRY_PROFILE=integration-with-migration
```

#### 4. migrator-light
**Назначение**: Миграторы с минимальным UI  
**Профили**: LifecycleProfile + UIProfile (minimal) + MigrationProfile  
**Исключения**: 
- `ui.screen.*`
- `ui.widget.*`
- `ui.form.*`
- `session.*`
- `external.*`

**Атрибуты**: ~135 атрибутов

Оптимизирован для миграторов где UI используется только для запуска и мониторинга.

**Использование**:
```dotenv
OTEL_TELEMETRY_PROFILE=migrator-light
```

#### 5. migrator-advanced
**Назначение**: Миграторы с полной RFC-compliant телеметрией  
**Профили**: LifecycleProfile + UIProfile + MigrationProfile  
**Атрибуты**: ~159 атрибутов (все migration атрибуты)

Полный набор атрибутов для соответствия RFC requirements.

**Использование**:
```dotenv
OTEL_TELEMETRY_PROFILE=migrator-advanced
```

#### 6. development
**Назначение**: Разработка и отладка  
**Профили**: Все профили  
**Атрибуты**: ~193 атрибута

**⚠️ ВНИМАНИЕ**: Только для development окружения!

**Использование**:
```dotenv
OTEL_TELEMETRY_PROFILE=development
```

> **⚠️ ВНИМАНИЕ**: Не устанавливайте `development` в production `.env`!

## Структура профиля

Каждый профиль в конфигурации имеет следующую структуру:

```yaml
profile-name:
    profiles:
        - App\Service\Telemetry\Profiles\LifecycleProfile
        - App\Service\Telemetry\Profiles\UIProfile
    exclude_patterns:
        - 'pattern.*'
        - 'another.pattern.*'
    description: 'Human-readable description'
```

### Поля:

- **profiles**: Список классов профилей для композиции
- **exclude_patterns**: Wildcard patterns для исключения атрибутов
- **description**: Описание профиля

## Exclusion Patterns

Exclusion patterns позволяют исключить определенные атрибуты из профиля:

### Точное совпадение:
```yaml
exclude_patterns:
    - 'sync.id'  # Исключит только sync.id
```

### Wildcard patterns:
```yaml
exclude_patterns:
    - 'sync.*'           # Исключит все sync.* атрибуты
    - '*.id'             # Исключит все атрибуты заканчивающиеся на .id
    - 'migration.batch.*' # Исключит все migration.batch.* атрибуты
```

## Конфигурация по окружениям

Профиль управляется **только через `.env`** для всех окружений. Блоки `when@dev/test/prod` в `telemetry.yaml` закомментированы — используйте их только если нужно жёстко зафиксировать профиль вне зависимости от `.env`.

### Development
```dotenv
# b24-ai-starter-ru/.env
OTEL_TELEMETRY_PROFILE=simple-ui
# Для отладки всех атрибутов:
# OTEL_TELEMETRY_PROFILE=development
```

### Test
```dotenv
# b24-ai-starter-ru/.env (или .env.test.local)
OTEL_TELEMETRY_PROFILE=simple-ui
```

### Production
```dotenv
# b24-ai-starter-ru/.env (продакшн секреты)
OTEL_TELEMETRY_PROFILE=simple-ui
```

## Использование в коде

Профили автоматически загружаются из конфигурации и применяются в `RealTelemetryService` через `AttributeGroupManager`.

### Получение активного профиля:

```php
$activeProfile = $container->getParameter('telemetry.active_profile');
```

### Получение конфигурации профилей:

```php
$profiles = $container->getParameter('telemetry.profiles');
$simpleUiConfig = $profiles['simple-ui'];
```

## Создание собственного профиля

1. Определите профиль в `telemetry.yaml`:

```yaml
my-custom-profile:
    profiles:
        - App\Service\Telemetry\Profiles\LifecycleProfile
        - App\Service\Telemetry\Profiles\UIProfile
    exclude_patterns:
        - 'ui.form.*'
    description: 'Custom profile for specific use case'
```

2. Активируйте профиль через `.env`:

```dotenv
OTEL_TELEMETRY_PROFILE=my-custom-profile
```

3. Очистите кэш:

```bash
make clear-cache  # или rm -rf var/cache/*
```

## Проверка конфигурации

Запустите тесты конфигурации:

```bash
make test-telemetry-config
```

Или напрямую:

```bash
vendor/bin/phpunit tests/Telemetry/Config/TelemetryConfigTest.php
```

## Best Practices

1. **Production**: Используйте минимальный профиль необходимый для вашего приложения
2. **Development**: Можно использовать `development` профиль для отладки
3. **Exclusion patterns**: Предпочитайте wildcard patterns точному перечислению
4. **Тестирование**: Всегда тестируйте новые профили перед деплоем
5. **Документация**: Документируйте причину выбора конкретного профиля

## Troubleshooting

### Атрибуты не фильтруются

1. Проверьте что кэш очищен: `rm -rf var/cache/`
2. Проверьте что профиль активен: `$container->getParameter('telemetry.active_profile')`
3. Проверьте логи RealTelemetryService для filtered_out атрибутов

### Профиль не загружается

1. Проверьте синтаксис YAML: `php bin/console lint:yaml config/packages/telemetry.yaml`
2. Проверьте что все классы профилей существуют
3. Очистите кэш: `rm -rf var/cache/`

### Тесты падают

1. Очистите test кэш: `rm -rf var/cache/test`
2. Запустите тесты снова: `make test-telemetry-config`

## См. также

- [AttributeGroupManager](../../backends/php/src/Service/Telemetry/AttributeGroupManager.php) - менеджер композиции профилей
- [Profile System Architecture](../../PROFILE_ARCHITECTURE.md) - архитектура системы профилей
- [Telemetry Integration Guide](../../DEVELOPER_GUIDE_TELEMETRY.md) - интеграция телеметрии
