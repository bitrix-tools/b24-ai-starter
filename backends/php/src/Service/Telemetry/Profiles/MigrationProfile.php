<?php

declare(strict_types=1);

namespace App\Service\Telemetry\Profiles;

/**
 * MigrationProfile — профиль для приложений-миграторов (тип 3).
 *
 * Профиль для односторонних миграций больших объёмов данных.
 * Наследует IntegrationProfile и добавляет специфичные для миграции атрибуты:
 * стадии (stages), пакеты (batches), coverage, liveness, retry logic.
 *
 * **Характеристики:**
 * - Длительные операции (часы - дни)
 * - Большие объёмы данных (тысячи - миллионы записей)
 * - Многоэтапная обработка (discovery → planning → execution → validation)
 * - Важны метрики coverage, completion, liveness
 * - One-shot execution (однократный запуск)
 *
 * **Примеры приложений:**
 * - Миграция из Trello в Bitrix24
 * - Миграция из Jira в Bitrix24
 * - Миграция из Asana в Bitrix24
 *
 * **Ключевые события:**
 * - migration_initiated, migration_started, migration_completed, migration_failed
 * - migration_stage_started, migration_stage_completed, migration_stage_failed
 * - migration_batch_started, migration_batch_completed
 * - migration_item_processed, migration_item_failed
 * - migration_paused, migration_resumed
 * - coverage_check, liveness_check
 *
 * **Группы атрибутов:**
 * - Все из IntegrationProfile (sync, entity, conflict, integration)
 * - Migration Process (migration.*)
 * - Versioning для retry (migration.attempt_number, migration.retry_of, migration.parent_migration_id)
 * - Stages (stage.*)
 * - Batches (batch.*)
 * - Retry Logic (retry.*)
 * - Items (items.*)
 * - Coverage/Quality (objects.*, coverage.*)
 * - Liveness (process.*)
 * - Performance (process.*)
 * - Error Classification (error.*)
 *
 * **Используется в комбинации:**
 * - LifecycleProfile + UIProfile (минимальный) + MigrationProfile (профиль: migrator-light, migrator-advanced)
 *
 * @see PROFILE_ARCHITECTURE.md Документация по архитектуре профилей
 * @see IntegrationProfile Базовый профиль для всех интеграций
 */
class MigrationProfile extends IntegrationProfile
{
    protected string $name = 'migration';

    protected string $description = 'One-shot migrator applications: Trello, Jira, Asana to Bitrix24 (type 3)';

    /**
     * {@inheritdoc}
     *
     * Наследует все атрибуты IntegrationProfile и добавляет специфичные для миграции
     */
    protected function defineAttributes(): array
    {
        return array_merge(
            parent::defineAttributes(),
            [
                // Migration Process
                'migration.id',                       // уникальный ID миграции (вместо sync.id)
                'migration.type',                     // full, selective, test, dry_run
                'migration.source_system',            // trello, jira, asana, etc.
                'migration.trigger',                  // user_initiated, scheduled, retry
                'migration.status',                   // initiated, running, paused, completed, failed
                'migration.outcome',                  // success, failure, cancelled, timeout, partial_success
                'migration.completion_percentage',    // 0-100
                'migration.is_complete',              // true/false
                'migration.can_resume',               // true/false
                'migration.resume_from_stage',        // ID стадии для возобновления

                // Versioning (для retry)
                'migration.attempt_number',           // номер попытки (1, 2, 3...)
                'migration.retry_of',                 // migration.id предыдущей попытки
                'migration.parent_migration_id',      // ID родительской миграции

                // Stages (стадии миграции)
                'stage.id',                           // ID стадии
                'stage.name',                         // discovery, users, projects, tasks, attachments, validation
                'stage.index',                        // порядковый номер (0-based)
                'stage.status',                       // pending, running, completed, failed, skipped
                'stage.duration_ms',                  // длительность выполнения
                'stage.start_time',                   // начало
                'stage.end_time',                     // завершение
                'stage.items_total',                  // всего элементов в стадии
                'stage.items_processed',              // обработано
                'stage.items_failed',                 // провалено
                'stage.throughput_ops',               // элементов в секунду

                // Batches (пакеты обработки)
                'batch.id',                           // ID пакета
                'batch.index',                        // индекс пакета в стадии
                'batch.size',                         // размер пакета
                'batch.offset',                       // смещение в общем списке
                'batch.status',                       // running, completed, failed
                'batch.duration_ms',                  // длительность

                // Retry Logic
                'retry.attempt',                      // номер попытки для элемента/пакета
                'retry.max_attempts',                 // максимум попыток
                'retry.reason',                       // причина retry
                'retry.outcome',                      // success, failure, skipped
                'retry.next_attempt_delay_ms',        // задержка до следующей попытки
                'retry.strategy',                     // exponential_backoff, linear, immediate

                // Items (элементы данных)
                'items.type',                         // users, projects, tasks, comments, attachments
                'items.total_planned',                // всего запланировано
                'items.total_processed',              // обработано всего
                'items.successful',                   // успешно
                'items.failed',                       // провалено
                'items.skipped',                      // пропущено
                'items.ids',                          // массив ID обработанных элементов

                // Coverage/Quality (RFC SLI-4)
                'objects.type',                       // тип объектов (users, projects, tasks)
                'objects.detected',                   // обнаружено в источнике
                'objects.planned',                    // запланировано к импорту
                'objects.imported',                   // успешно импортировано
                'objects.failed',                     // провалено при импорте
                'objects.skipped',                    // пропущено (бизнес-логика)
                'coverage.percentage',                // objects.imported / objects.detected * 100
                'coverage.status',                    // full, partial, failed

                // Liveness (RFC SLI-2)
                'process.last_activity_timestamp',    // когда был последний прогресс
                'process.last_stage_completed',       // название последнего завершённого stage
                'process.idle_duration_ms',           // как долго нет активности
                'process.is_stale',                   // true если нет прогресса > threshold
                'process.stale_threshold_ms',         // порог для считания stale
                'process.expected_completion_time',   // ожидаемое время завершения
                'process.heartbeat_interval_ms',      // как часто процесс должен сигнализировать
                'process.last_heartbeat',             // timestamp последнего heartbeat

                // Performance
                'process.start_timestamp',            // начало миграции
                'process.end_timestamp',              // завершение (если завершена)
                'process.duration_total_ms',          // полная длительность
                'process.duration_planned_ms',        // ожидаемая длительность
                'process.throughput_ops',             // объектов в секунду (общий)
                'process.throughput_bytes',           // байт в секунду
                'process.avg_item_duration_ms',       // среднее время обработки элемента

                // Error Classification (операционные метрики)
                'error.source',                       // external_api, auth, network, validation, internal, bitrix_api
                'error.service',                      // bitrix_api, trello_api, jira_api, storage, queue
                'error.is_retryable',                 // true/false
                'error.category',                     // user_error, system_error, external_error, config_error
                'error.http_status',                  // HTTP статус (для API ошибок)
                'error.rate_limit',                   // true/false (rate limiting)
                'error.affects_completion',           // true/false - блокирует ли завершение
                'error.data_loss_risk',               // true/false - есть ли риск потери данных

                // Failure Reasons
                'migration.failure_reason',           // error, timeout, user_cancelled, resource_limit, validation_failed
            ],
        );
    }
}
