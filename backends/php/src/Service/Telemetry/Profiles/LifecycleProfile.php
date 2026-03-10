<?php

declare(strict_types=1);

namespace App\Service\Telemetry\Profiles;

/**
 * LifecycleProfile — профиль для lifecycle событий приложения.
 *
 * Базовый профиль, обязательный для всех типов приложений.
 * Покрывает события установки, обновления, удаления, конфигурации
 * и регистрации элементов (event handlers, placements, robots, triggers).
 *
 * **Ключевые события:**
 * - app_installed, app_updated, app_uninstalled, app_configured
 * - event_subscription_registered, event_subscription_failed
 * - placement_registered, robot_registered, trigger_registered
 * - menu_item_registered
 *
 * **Группы атрибутов:**
 * - Идентификация приложения (app.*)
 * - Информация о портале (portal.*)
 * - Lifecycle события (lifecycle.*)
 * - Регистрация элементов (registration.*)
 * - Event Handlers (event_handler.*)
 * - Placements/встройки (placement.*)
 * - Автоматизация (automation.*)
 *
 * @see PROFILE_ARCHITECTURE.md Документация по архитектуре профилей
 */
class LifecycleProfile extends BaseProfile
{
    protected string $name = 'lifecycle';

    protected string $description = 'Lifecycle events: installation, updates, configuration, and element registration (base profile for all app types)';

    protected function defineAttributes(): array
    {
        return [
            // Telemetry routing
            'telemetry.channel',           // analytics | system | support (переопределяет default в RealTelemetryService)

            // Идентификация приложения
            'app.id',                      // ID приложения в маркетплейсе
            'app.version',                 // версия приложения
            'app.previous_version',        // предыдущая версия (при обновлении)

            // Портал
            'portal.id',                   // ID портала Bitrix24
            'portal.member_id',            // идентификатор портала (аналог app.id + portal)
            'portal.domain',               // домен портала
            'portal.region',               // регион портала (ru, eu, us, etc.)
            'portal.plan',                 // тарифный план (free, basic, professional, etc.)

            // Lifecycle события
            'lifecycle.event_type',        // install, update, uninstall, configure
            'lifecycle.status',            // success, failure, in_progress
            'lifecycle.trigger',           // user_initiated, auto_update, system

            // Регистрация элементов
            'registration.type',           // event_handler, placement, robot, trigger, menu_item
            'registration.scope',          // global, user, department
            'registration.count',          // количество зарегистрированных элементов

            // Event Handlers
            'event_handler.code',          // код события Bitrix24 (ONAPPINSTALL, ONCRMLEADADD, etc.)
            'event_handler.status',        // registered, failed, unregistered
            'event_handler.error_reason',  // причина ошибки регистрации

            // B24 Event Processing (b24_event_processed, b24_event_action_initiated)
            'b24.event',                   // код входящего события B24 (ONCRMDEALUPDATE, ONCRMCONTACTADD)
            'b24.entity',                  // тип сущности: deal, contact, lead
            'b24.contact_id',              // ID контакта/сущности B24

            // Placements (встройки)
            'placement.code',              // код размещения (CRM_DEAL_DETAIL_TAB, etc.)
            'placement.title',             // название встройки
            'placement.url',               // URL обработчика

            // Robots/Triggers (автоматизация)
            'automation.type',             // robot, trigger
            'automation.code',             // код автоматизации
            'automation.scope',            // crm, tasks, bizproc
        ];
    }
}
