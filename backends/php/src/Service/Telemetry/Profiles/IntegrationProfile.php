<?php

declare(strict_types=1);

namespace App\Service\Telemetry\Profiles;

/**
 * IntegrationProfile — профиль для приложений с синхронизацией (тип 2).
 *
 * Профиль для приложений с периодической или event-driven синхронизацией данных.
 * Может включать начальный односторонний перенос (initial sync) с последующей
 * двусторонней или односторонней регулярной синхронизацией.
 *
 * **Характеристики:**
 * - Асинхронные операции (фоновые задачи)
 * - Средняя длительность (минуты - часы)
 * - Важна надёжность синхронизации, обработка конфликтов
 * - Incremental sync (дельта изменений)
 *
 * **Примеры приложений:**
 * - Интеграции с 1С
 * - Синхронизация с amoCRM
 * - Синхронизация с внешними хранилищами
 * - Интеграция с календарями (Google Calendar, Outlook)
 *
 * **Ключевые события:**
 * - sync_initiated, sync_started, sync_completed, sync_failed
 * - sync_conflict_detected, sync_conflict_resolved
 * - initial_sync_started, initial_sync_completed
 * - entity_synced, entity_sync_failed
 *
 * **Группы атрибутов:**
 * - Sync Process (sync.*)
 * - External System (integration.*)
 * - Data Sync (entity.*)
 * - Volumes (sync.entities_*)
 * - Conflict Resolution (conflict.*)
 * - Performance (sync.throughput_eps, sync.avg_entity_duration_ms, sync.batch_size)
 * - Initial Sync (initial_sync.*)
 *
 * **Используется в комбинации:**
 * - LifecycleProfile + UIProfile + IntegrationProfile (профиль: integration-sync)
 * - LifecycleProfile + UIProfile + IntegrationProfile (профиль: integration-with-migration)
 *
 * @see PROFILE_ARCHITECTURE.md Документация по архитектуре профилей
 */
class IntegrationProfile extends BaseProfile
{
    protected string $name = 'integration';

    protected string $description = 'Integrations with data synchronization: 1C, amoCRM, calendars (type 2)';

    protected function defineAttributes(): array
    {
        return [
            // Sync Process
            'sync.id',                         // уникальный ID процесса синхронизации
            'sync.type',                       // initial, incremental, full, manual
            'sync.direction',                  // bidirectional, to_bitrix, from_bitrix
            'sync.trigger',                    // scheduled, event_driven, manual, webhook
            'sync.status',                     // initiated, running, completed, failed, paused
            'sync.start_time',                 // начало синхронизации
            'sync.end_time',                   // завершение синхронизации
            'sync.duration_ms',                // длительность

            // External System
            'integration.system_name',         // название внешней системы (1c, amocrm, google_calendar)
            'integration.system_version',      // версия API внешней системы
            'integration.auth_type',           // oauth, api_key, basic_auth, webhook

            // Data Sync
            'entity.type',                     // тип сущности (lead, contact, deal, task, event)
            'entity.bitrix_id',                // ID в Bitrix24
            'entity.external_id',              // ID во внешней системе
            'entity.operation',                // create, update, delete, skip
            'entity.status',                   // synced, failed, skipped, conflict

            // Volumes
            'sync.entities_total',             // всего сущностей для синхронизации
            'sync.entities_processed',         // обработано
            'sync.entities_synced',            // успешно синхронизировано
            'sync.entities_failed',            // провалено
            'sync.entities_skipped',           // пропущено
            'sync.entities_conflict',          // с конфликтами

            // Conflict Resolution
            'conflict.type',                   // update_conflict, delete_conflict, duplicate
            'conflict.resolution',             // bitrix_wins, external_wins, merge, manual
            'conflict.field',                  // поле с конфликтом
            'conflict.bitrix_value',           // значение в Bitrix24
            'conflict.external_value',         // значение во внешней системе

            // Performance
            'sync.throughput_eps',             // entities per second
            'sync.avg_entity_duration_ms',     // среднее время обработки сущности
            'sync.batch_size',                 // размер пакета обработки

            // Initial Sync (односторонний перенос для типа 2+)
            'initial_sync.total_entities',     // всего сущностей для переноса
            'initial_sync.completed_entities', // перенесено
            'initial_sync.progress_percentage', // процент выполнения
            'initial_sync.estimated_time_remaining_ms', // оставшееся время (оценка)
        ];
    }
}
