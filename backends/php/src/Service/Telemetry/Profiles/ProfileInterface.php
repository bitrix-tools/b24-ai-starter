<?php

declare(strict_types=1);

namespace App\Service\Telemetry\Profiles;

/**
 * ProfileInterface — контракт для профилей телеметрии.
 *
 * Профиль определяет набор разрешённых атрибутов для событий определённого типа.
 * Это позволяет фильтровать данные телеметрии в зависимости от типа приложения:
 * - LifecycleProfile: события установки, обновления, конфигурации (базовый для всех)
 * - UIProfile: UI-Centric приложения (типа 1)
 * - IntegrationProfile: приложения с синхронизацией (типа 2)
 * - MigrationProfile: приложения-миграторы (типа 3)
 *
 * @see https://github.com/bitrix24/b24phpsdk Bitrix24 PHP SDK
 */
interface ProfileInterface
{
    /**
     * Возвращает список разрешённых атрибутов для профиля.
     *
     * @return array<string> Массив имён атрибутов, допустимых в рамках профиля
     *
     * @example
     * ```php
     * [
     *   'app.id',
     *   'app.version',
     *   'portal.id',
     *   'lifecycle.event_type',
     *   // ...
     * ]
     * ```
     */
    public function getAllowedAttributes(): array;

    /**
     * Возвращает имя профиля.
     *
     * @return string Краткое имя профиля (lowercase, kebab-case)
     *
     * @example 'lifecycle', 'ui', 'integration', 'migration'
     */
    public function getName(): string;

    /**
     * Возвращает описание профиля.
     *
     * @return string Описание назначения и use cases профиля
     */
    public function getDescription(): string;
}
