<?php

declare(strict_types=1);

namespace App\Service\Telemetry\Profiles;

/**
 * UIProfile — профиль для UI-Centric приложений (тип 1).
 *
 * Основной профиль для приложений, где основная логика выполняется в UI,
 * а бэкенд выполняет отдельные команды по запросу с фронта.
 *
 * **Характеристики:**
 * - Синхронные операции (пользователь ждёт ответа)
 * - Короткое время выполнения (< 30 секунд)
 * - Важна UX метрика (отклик, успешность операции)
 * - Может быть встройкой в UI Bitrix24
 *
 * **Примеры приложений:**
 * - CRM виджеты
 * - Платежные системы
 * - SMS-провайдеры
 * - Дашборды и формы
 *
 * **Ключевые события:**
 * - app_opened, screen_view, button_clicked, form_submitted
 * - action_initiated, action_completed, action_failed
 * - external_api_call, bitrix_api_call
 *
 * **Группы атрибутов:**
 * - UI Navigation (ui.*, screen.*)
 * - User Interaction (interaction.*, button.*, form.*, widget.*)
 * - Session (session.*, user.*)
 * - Action Execution (action.*)
 * - External Systems (external.*)
 * - API Calls (api.*)
 *
 * **Используется в комбинации:** LifecycleProfile + UIProfile (профиль конфигурации: simple-ui)
 *
 * @see PROFILE_ARCHITECTURE.md Документация по архитектуре профилей
 */
class UIProfile extends BaseProfile
{
    protected string $name = 'ui';

    protected string $description = 'UI-Centric applications: widgets, forms, payments, SMS (type 1)';

    protected function defineAttributes(): array
    {
        return [
            // Event routing
            'event.source',                // frontend | backend (для фильтрации в дашбордах)

            // UI Navigation
            'ui.surface',                  // placement, iframe, slider, popup
            'ui.placement_code',           // код размещения встройки
            'ui.path',                     // URL-путь страницы (от Nuxt router)
            'ui.route_name',               // имя маршрута Nuxt
            'ui.route_params',             // параметры маршрута (JSON-строка)
            'ui.button_id',                // ID кнопки (для ui_button_click)
            'ui.user_agent',               // User-Agent браузера (от app_frame_loaded)
            'screen.name',                 // название экрана
            'screen.section',              // раздел приложения (settings, main, reports)

            // User Interaction
            'interaction.type',            // click, submit, input, select, drag
            'button.name',                 // название кнопки
            'button.action',               // действие кнопки
            'form.id',                     // ID формы
            'form.type',                   // создание, редактирование, фильтр
            'widget.placement',            // расположение виджета

            // Session
            'session.id',                  // ID сессии пользователя
            'session.start_time',          // начало сессии
            'session.duration_ms',         // длительность сессии
            'user.id',                     // ID пользователя Bitrix24
            'user.role',                   // роль пользователя (admin, user, integrator)

            // Action Execution (команды с фронта)
            'action.name',                 // название действия (send_sms, process_payment, fetch_data)
            'action.type',                 // bitrix_api, external_api, internal
            'action.status',               // initiated, in_progress, completed, failed
            'action.duration_ms',          // время выполнения
            'action.sync',                 // true (синхронное), false (асинхронное)

            // External System (платежи, SMS, email, etc.)
            'external.system_name',        // название внешней системы
            'external.operation_type',     // payment, sms, email, api_call
            'external.operation_id',       // ID операции во внешней системе
            'external.status',             // pending, completed, failed, refunded
            'external.amount',             // сумма (для платежей)
            'external.currency',           // валюта
            'external.recipient',          // получатель (номер телефона, email)

            // API Calls (backend)
            'api.provider',                // bitrix24, stripe, twilio, external_system
            'api.method',                  // название метода API
            'api.endpoint',                // эндпоинт
            'api.http_status',             // HTTP статус ответа
            'api.duration_ms',             // время выполнения запроса
            'api.retry_count',             // количество попыток
            'api.status',                  // success, error

            // B24 JS SDK Calls (frontend b24_api_call events)
            'b24.method',                  // метод JS SDK: crm.deal.list, placement.info и т.п.
            'b24.command',                 // команда pull: application.event.add и т.п.

            // Frontend Errors (ui_error events)
            'error.type',                  // uncaught_exception | unhandled_rejection
            'error.message',               // сообщение об ошибке
            'error.filename',              // файл где произошла ошибка
        ];
    }
}
